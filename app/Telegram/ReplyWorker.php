<?php

namespace App\Telegram;

use App\Access\AccessService;
use App\Operations\MaintenanceGuard;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ReplyWorker
{
    public function __construct(private TelegramClient $client, private QueueLeases $leases, private DispatchRateGate $rateGate, private AccessService $access, private TelegramAudience $audience) {}

    public function runOnce(): bool
    {
        if (app()->isDownForMaintenance() || (class_exists(MaintenanceGuard::class) && app(MaintenanceGuard::class)->isPaused())) {
            return false;
        }
        $row = $this->leases->claim('telegram_replies');
        if ($row === null) {
            return false;
        }
        if (! $this->authorized($row)) {
            $this->leases->release('telegram_replies', $row, 'cancelled', 'Private reply no longer authorized.');

            return true;
        }
        if ((int) $row->attempts >= max(1, (int) config('telegram.max_attempts', 12))) {
            $this->leases->release('telegram_replies', $row, 'failed', 'Reply attempt limit reached.');

            return true;
        }
        $delay = $this->rateGate->reserve((string) $row->chat_id);
        if ($delay > 0) {
            $this->leases->release('telegram_replies', $row, 'retry', delaySeconds: max(1, (int) ceil($delay / 1000)));

            return true;
        }
        if (app()->isDownForMaintenance() || (class_exists(MaintenanceGuard::class) && app(MaintenanceGuard::class)->isPaused())) {
            $this->leases->release('telegram_replies', $row, 'retry', 'Delivery paused.', 1);

            return true;
        }
        if (! $this->authorized($row)) {
            $this->leases->release('telegram_replies', $row, 'cancelled', 'Private reply no longer authorized.');

            return true;
        }
        if (! $this->leases->beginAttempt('telegram_replies', $row)) {
            return true;
        }
        try {
            $messageId = $this->client->sendMessage((string) $row->chat_id, $row->text);
            $this->leases->success('telegram_replies', $row, $messageId);
        } catch (TelegramException $exception) {
            if ($exception->status === 429) {
                $this->rateGate->postpone((string) $row->chat_id, $exception->retryAfter ?? 1);
            }
            $retry = $exception->retryable() && (int) $row->attempts + 1 < max(1, (int) config('telegram.max_attempts', 12));
            $delay = $exception->retryAfter ?? min(3600, 2 ** min((int) $row->attempts + 1, 11) + random_int(0, 3));
            $this->leases->release('telegram_replies', $row, $retry ? 'retry' : 'failed', $exception->getMessage(), $delay);
        } catch (LogicException) {
            $this->leases->release('telegram_replies', $row, 'failed', 'Telegram configuration is missing or invalid.');
        }

        return true;
    }

    private function authorized(object $row): bool
    {
        return $row->user_id !== null
            && $this->audience->allows((string) $row->chat_id)
            && DB::table('users')->where('id', $row->user_id)->where('telegram_user_id', $row->chat_id)->exists()
            && $this->access->activeGrant((int) $row->user_id) !== null;
    }
}
