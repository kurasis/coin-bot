<?php

namespace App\Telegram;

use App\Access\AccessDenied;
use App\Access\AccessService;
use App\Operations\MaintenanceGuard;
use App\Wallets\WalletService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

final class TelegramPoller
{
    private const ANONYMOUS_UPDATE_WINDOW = 1000;

    private const MAX_ANONYMOUS_PRUNE = 1000;

    public function __construct(private TelegramClient $client, private AccessService $access, private TelegramAudience $audience) {}

    public function runOnce(): int
    {
        if ($this->paused()) {
            return 0;
        }
        $seconds = max(30, min(50, (int) config('telegram.poll_timeout', 25)) + max(1, min(60, (int) config('telegram.connect_timeout', 15))) + 20);
        $result = Cache::lock('telegram:poller', $seconds)->get(function (): int {
            $offset = (int) (DB::table('telegram_update_offsets')->where('worker', 'main')->value('next_update_id') ?? 0);
            $updates = $this->client->getUpdates($offset);
            foreach ($updates as $update) {
                if (! is_array($update) || ! is_int($update['update_id'] ?? null) || $update['update_id'] < 0 || $update['update_id'] >= PHP_INT_MAX) {
                    throw new TelegramException(0);
                }
            }
            usort($updates, fn (array $a, array $b) => $a['update_id'] <=> $b['update_id']);
            $processed = 0;
            foreach ($updates as $update) {
                if (! is_array($update) || ! is_int($update['update_id'] ?? null) || $update['update_id'] < 0) {
                    throw new TelegramException(0);
                }
                if ($update['update_id'] < $offset) {
                    continue;
                }
                if ($this->paused()) {
                    break;
                }
                DB::transaction(function () use ($update): void {
                    $id = $update['update_id'];
                    if (! DB::table('telegram_updates')->where('update_id', $id)->exists()) {
                        $this->process($update);
                    }
                    $current = DB::table('telegram_update_offsets')->where('worker', 'main')->value('next_update_id');
                    DB::table('telegram_update_offsets')->updateOrInsert(['worker' => 'main'], [
                        'next_update_id' => max((int) $current, $id + 1), 'updated_at' => now(), 'created_at' => now(),
                    ]);
                }, 3);
                $offset = $update['update_id'] + 1;
                $processed++;
            }

            // The durable offset already acknowledges these old updates. Keep
            // anonymous traffic bounded without deleting activation evidence or
            // authenticated audit history; cleanup is also bounded per poll.
            if (! $this->paused() && $offset > self::ANONYMOUS_UPDATE_WINDOW) {
                $stale = DB::table('telegram_updates')->select('id')
                    ->whereNull('user_id')->whereNull('access_key_id')
                    ->where('update_id', '<', $offset - self::ANONYMOUS_UPDATE_WINDOW)
                    ->orderBy('update_id')->limit(self::MAX_ANONYMOUS_PRUNE);
                DB::table('telegram_updates')->whereIn('id', $stale)->delete();
            }

            return $processed;
        });

        return (int) $result;
    }

    private function process(#[\SensitiveParameter] array $update): void
    {
        $id = $update['update_id'];
        $message = $update['message'] ?? null;
        $actor = is_array($message) ? ($message['from']['id'] ?? null) : null;
        $chatId = is_array($message) ? ($message['chat']['id'] ?? null) : null;
        $private = is_array($message) && ($message['chat']['type'] ?? null) === 'private'
            && (is_int($actor) || is_string($actor)) && (is_int($chatId) || is_string($chatId))
            && preg_match('/^[1-9][0-9]{0,18}$/D', (string) $actor)
            && (string) $actor === (string) $chatId && ($message['from']['is_bot'] ?? false) === false;
        if (! $private) {
            $this->record($id, 'ignored');

            return;
        }
        $actor = (string) $actor;
        if (! $this->audience->allows($actor)) {
            $this->record($id, 'ignored');

            return;
        }
        $limiter = 'telegram:commands:'.hash('sha256', $actor);
        if (RateLimiter::tooManyAttempts($limiter, 20)) {
            $this->record($id, 'rate_limited');

            return;
        }
        RateLimiter::hit($limiter, 60);
        $text = is_string($message['text'] ?? null) ? $message['text'] : '';
        $user = DB::table('users')->where('telegram_user_id', $actor)->first();
        $hadActiveAccess = $user !== null && $this->access->activeGrant((int) $user->id) !== null;
        $reply = null;
        $result = 'ignored';
        if (preg_match('/^\/start(?:@[A-Za-z0-9_]+)?\s+([A-Za-z0-9_-]{43})\s*$/D', $text, $matches)) {
            try {
                $activation = $this->access->activate($matches[1], $actor, $id);
                $user = DB::table('users')->find($activation->userId);
                $this->privateDestination((int) $user->id, $actor, true, $hadActiveAccess);
                if (! $activation->replayed) {
                    app(WalletService::class)->refreshWatchSetsForUser((int) $user->id, ! $hadActiveAccess);
                }
                $reply = 'Access activated. Your notifications are private. Use /status, /stop, or /resume.';
                $result = 'activated';
            } catch (AccessDenied) {
                if ($hadActiveAccess) {
                    $reply = 'Access could not be activated. Request a valid individual key from the operator.';
                }
                $result = 'denied';
            }
        } elseif ($hadActiveAccess) {
            if (preg_match('/^\/stop(?:@[A-Za-z0-9_]+)?\s*$/D', $text)) {
                $this->privateDestination((int) $user->id, $actor, false);
                $reply = 'Your notifications are paused. Use /resume to enable them again.';
                $result = 'stopped';
            } elseif (preg_match('/^\/resume(?:@[A-Za-z0-9_]+)?\s*$/D', $text)) {
                $this->privateDestination((int) $user->id, $actor, true);
                $reply = 'Your notifications are enabled.';
                $result = 'resumed';
            } else {
                $permission = DB::table('user_chat_destinations as p')->join('chat_destinations as d', 'd.id', 'p.destination_id')
                    ->where('p.user_id', $user->id)->where('d.chat_id', $actor)->where('p.enabled', true)->whereNull('p.revoked_at')
                    ->where('d.enabled', true)->whereNull('d.suspended_at')->exists();
                $reply = 'Access active. Notifications: '.($permission ? 'enabled' : 'paused').'.';
                $result = 'status';
            }
        }
        if (! DB::table('telegram_updates')->where('update_id', $id)->exists()) {
            // Previously enrolled accounts with lapsed access are still
            // unauthenticated traffic and must share the bounded audit window.
            $this->record($id, $result, $hadActiveAccess ? $user?->id : null);
        }
        if ($reply === null) {
            return;
        }
        DB::table('telegram_replies')->insertOrIgnore([
            'update_id' => $id, 'chat_id' => $actor, 'user_id' => $user?->id,
            'text' => $reply, 'status' => 'pending', 'available_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function privateDestination(int $userId, string $chatId, bool $enabled, bool $preserveExisting = false): void
    {
        $destination = DB::table('chat_destinations')->where('chat_id', $chatId)->first();
        if ($destination !== null && $destination->kind !== 'private') {
            throw new \LogicException('Private destination identity conflict.');
        }
        $destinationId = $destination?->id ?? DB::table('chat_destinations')->insertGetId([
            'chat_id' => $chatId, 'kind' => 'private', 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($preserveExisting) {
            // A new key for an already active account is not an implicit /resume.
            DB::table('user_chat_destinations')->insertOrIgnore([
                'user_id' => $userId, 'destination_id' => $destinationId, 'enabled' => $enabled,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return;
        }
        if ($enabled) {
            DB::table('chat_destinations')->where('id', $destinationId)->update(['suspended_at' => null, 'updated_at' => now()]);
        }
        DB::table('user_chat_destinations')->updateOrInsert(['user_id' => $userId, 'destination_id' => $destinationId], [
            'enabled' => $enabled, 'revoked_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function record(int $updateId, string $result, ?int $userId = null): void
    {
        DB::table('telegram_updates')->insert([
            'update_id' => $updateId, 'user_id' => $userId, 'result' => $result,
            'processed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function paused(): bool
    {
        return app()->isDownForMaintenance()
            || (class_exists(MaintenanceGuard::class) && app(MaintenanceGuard::class)->isPaused());
    }
}
