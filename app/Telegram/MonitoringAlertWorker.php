<?php

namespace App\Telegram;

use App\Operations\MaintenanceGuard;
use App\Operations\MonitoringIncidentService;
use Illuminate\Support\Facades\DB;
use LogicException;

final class MonitoringAlertWorker
{
    public function __construct(
        private TelegramClient $client,
        private QueueLeases $leases,
        private DispatchRateGate $rateGate,
        private MonitoringIncidentService $incidents,
    ) {}

    public function runOnce(): bool
    {
        if ($this->paused()) {
            return false;
        }
        $row = $this->leases->claim('monitoring_alerts');
        if ($row === null) {
            return false;
        }
        if (! $this->incidents->deliverable($row)) {
            $this->cancel($row);

            return true;
        }
        if ((int) $row->attempts >= max(1, (int) config('telegram.max_attempts', 12))) {
            $this->leases->release('monitoring_alerts', $row, 'failed', 'Monitoring alert attempt limit reached.');
            $this->incidents->deliveryFinished((int) $row->id);

            return true;
        }
        $delay = $this->rateGate->reserve((string) $row->chat_id, monitoring: true);
        if ($delay > 0) {
            $this->leases->release('monitoring_alerts', $row, 'retry', delaySeconds: max(1, (int) ceil($delay / 1000)));

            return true;
        }
        $began = DB::transaction(function () use ($row): bool {
            if ($this->paused() || ! $this->incidents->deliverable($row)
                || ! $this->leases->beginAttempt('monitoring_alerts', $row)) {
                return false;
            }
            $this->leases->owned('monitoring_alerts', $row)->update(['possibly_delivered' => true, 'updated_at' => now()]);

            return true;
        }, 3);
        if (! $began) {
            if ($this->paused()) {
                $this->leases->release('monitoring_alerts', $row, 'retry', 'Monitoring delivery paused.', 1);
            } else {
                $this->cancel($row);
            }

            return true;
        }
        try {
            $messageId = $this->client->sendMessage((string) $row->chat_id, $row->text);
            $this->leases->success('monitoring_alerts', $row, $messageId);
        } catch (TelegramException $exception) {
            $this->leases->owned('monitoring_alerts', $row)->update(['possibly_delivered' => $exception->ambiguous, 'updated_at' => now()]);
            if ($exception->status === 429) {
                $this->rateGate->postpone((string) $row->chat_id, $exception->retryAfter ?? 1);
            }
            $retry = $exception->retryable() && (int) $row->attempts + 1 < max(1, (int) config('telegram.max_attempts', 12));
            $state = ! $this->incidents->deliverable($row) ? 'cancelled' : ($retry ? 'retry' : 'failed');
            $delay = $exception->retryAfter ?? min(3600, 2 ** min((int) $row->attempts + 1, 11) + random_int(0, 3));
            $this->leases->release('monitoring_alerts', $row, $state, $exception->getMessage(), $delay);
        } catch (LogicException) {
            $this->leases->owned('monitoring_alerts', $row)->update(['possibly_delivered' => false, 'updated_at' => now()]);
            $this->leases->release('monitoring_alerts', $row, 'failed', 'Telegram configuration is missing or invalid.');
        }
        $this->incidents->deliveryFinished((int) $row->id);

        return true;
    }

    private function cancel(object $row): void
    {
        $this->leases->release('monitoring_alerts', $row, 'cancelled', 'Monitoring state or private recipient no longer authorized.');
        $this->incidents->deliveryFinished((int) $row->id);
    }

    private function paused(): bool
    {
        return app()->isDownForMaintenance() || app(MaintenanceGuard::class)->isPaused();
    }
}
