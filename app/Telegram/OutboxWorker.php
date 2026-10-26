<?php

namespace App\Telegram;

use App\Access\AccessService;
use App\Monitoring\OutboxRouter;
use App\Operations\MaintenanceGuard;
use Illuminate\Support\Facades\DB;
use LogicException;

final class OutboxWorker
{
    public function __construct(
        private TelegramClient $client,
        private AccessService $access,
        private OutboxRouter $router,
        private QueueLeases $leases,
        private DispatchRateGate $rateGate,
        private MessageFormatter $formatter,
    ) {}

    public function runOnce(): bool
    {
        if ($this->paused()) {
            return false;
        }
        $row = $this->leases->claim('notification_outbox');
        if ($row === null) {
            return false;
        }
        $routes = $this->router->authorizedRoutes((int) $row->id);
        if ($routes->isEmpty() || ! $this->access->deliveryAuthorized((int) $row->id)) {
            $this->leases->release('notification_outbox', $row, 'cancelled', 'Delivery no longer authorized.');

            return true;
        }
        if ((int) $row->attempts >= max(1, (int) config('telegram.max_attempts', 12))) {
            $this->leases->release('notification_outbox', $row, 'failed', 'Delivery attempt limit reached.');

            return true;
        }
        $destination = DB::table('chat_destinations')->find($row->destination_id);
        $transfer = DB::table('transfers')->find($row->transfer_id);
        $delay = $this->rateGate->reserve((string) $destination->chat_id);
        if ($delay > 0) {
            $this->leases->release('notification_outbox', $row, 'retry', delaySeconds: max(1, (int) ceil($delay / 1000)));

            return true;
        }
        // Revalidate as close as possible to the HTTP request; no write transaction spans the request.
        $routes = $this->router->authorizedRoutes((int) $row->id);
        if ($this->paused()) {
            $this->leases->release('notification_outbox', $row, 'retry', 'Delivery paused.', 1);

            return true;
        }
        if ($routes->isEmpty() || ! $this->access->deliveryAuthorized((int) $row->id)) {
            $this->leases->release('notification_outbox', $row, 'cancelled', 'Delivery no longer authorized.');

            return true;
        }
        if (! $this->leases->beginAttempt('notification_outbox', $row)) {
            return true;
        }
        try {
            $messageId = $this->client->sendMessage((string) $destination->chat_id, $this->formatter->format($transfer, $destination, $routes));
            DB::transaction(function () use ($row, $destination, $messageId): void {
                $stillOwned = $this->leases->owned('notification_outbox', $row)->exists();
                $this->leases->success('notification_outbox', $row, $messageId);
                if (! $stillOwned) {
                    DB::table('notification_outbox')->where('id', $row->id)->where('status', 'cancelled')->update([
                        'telegram_message_id' => $messageId, 'sent_at' => now(), 'updated_at' => now(),
                    ]);
                }
                DB::table('chat_destinations')->where('id', $destination->id)->update(['last_sent_at' => now(), 'updated_at' => now()]);
                $this->attempt((int) $row->id, $stillOwned ? 'sent' : 'sent_after_cancel');
            });
        } catch (TelegramException $exception) {
            if ($exception->status === 429) {
                $this->rateGate->postpone((string) $destination->chat_id, $exception->retryAfter ?? 1);
            }
            $attempts = (int) $row->attempts + 1;
            $retry = $exception->retryable() && $attempts < max(1, (int) config('telegram.max_attempts', 12));
            $delay = $exception->retryAfter ?? min(3600, 2 ** min($attempts, 11) + random_int(0, 3));
            DB::transaction(function () use ($row, $destination, $exception, $retry, $delay): void {
                if ($exception->status === 403) {
                    DB::table('chat_destinations')->where('id', $destination->id)->update(['suspended_at' => now(), 'updated_at' => now()]);
                }
                $this->leases->release('notification_outbox', $row, $retry ? 'retry' : 'failed', $exception->getMessage(), $delay);
                $this->attempt((int) $row->id, $exception->ambiguous ? 'ambiguous' : ($retry ? 'retry' : 'failed'), $exception->status);
            });
        } catch (LogicException) {
            $this->leases->release('notification_outbox', $row, 'failed', 'Telegram configuration is missing or invalid.');
            $this->attempt((int) $row->id, 'failed', 0);
        }

        return true;
    }

    public function replay(int $outboxId): void
    {
        DB::transaction(function () use ($outboxId): void {
            $row = DB::table('notification_outbox')->find($outboxId);
            if ($row === null || $row->status !== 'failed' || $this->router->authorizedRoutes($outboxId)->isEmpty()) {
                throw new LogicException('Only an authorized failed delivery can be replayed.');
            }
            DB::table('notification_outbox')->where('id', $outboxId)->where('status', 'failed')->update([
                'status' => 'pending', 'attempts' => 0, 'available_at' => now(),
                'lease_token' => null, 'lease_expires_at' => null, 'last_error' => null, 'updated_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'action' => 'telegram.delivery_replayed', 'subject_type' => 'outbox', 'subject_id' => (string) $outboxId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    private function attempt(int $outboxId, string $outcome, ?int $code = null): void
    {
        DB::table('delivery_attempts')->insert([
            'outbox_id' => $outboxId, 'outcome' => $outcome,
            'error_code' => $code === null ? null : (string) $code,
            'error_message' => $code === null ? null : 'Telegram request failed.',
            'attempted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function paused(): bool
    {
        return class_exists(MaintenanceGuard::class)
            ? app(MaintenanceGuard::class)->isPaused()
            : app()->isDownForMaintenance();
    }
}
