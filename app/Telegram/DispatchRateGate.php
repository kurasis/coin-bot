<?php

namespace App\Telegram;

use Illuminate\Support\Facades\DB;

final class DispatchRateGate
{
    /** Reserve one global and private-chat slot atomically; return delay when unavailable. */
    public function reserve(string $chatId): int
    {
        return DB::transaction(function () use ($chatId): int {
            $now = now()->getTimestampMs();
            $names = ['global', 'chat:'.$chatId];
            foreach ($names as $name) {
                DB::table('telegram_dispatch_state')->insertOrIgnore(['name' => $name, 'next_allowed_ms' => 0]);
            }
            $states = DB::table('telegram_dispatch_state')->whereIn('name', $names)->pluck('next_allowed_ms', 'name');
            $delay = max(0, (int) $states['global'] - $now, (int) $states['chat:'.$chatId] - $now);
            if ($delay > 0) {
                return $delay;
            }
            $globalInterval = (int) ceil(1000 / max(1, min(29, (int) config('telegram.global_messages_per_second', 25))));
            DB::table('telegram_dispatch_state')->where('name', 'global')->update(['next_allowed_ms' => $now + $globalInterval]);
            DB::table('telegram_dispatch_state')->where('name', 'chat:'.$chatId)->update(['next_allowed_ms' => $now + (str_starts_with($chatId, '-') ? 3000 : 1000)]);

            return 0;
        }, 3);
    }

    public function postpone(string $chatId, int $seconds): void
    {
        DB::transaction(function () use ($chatId, $seconds): void {
            $until = now()->getTimestampMs() + max(1, $seconds) * 1000;
            foreach (['global', 'chat:'.$chatId] as $name) {
                DB::table('telegram_dispatch_state')->insertOrIgnore(['name' => $name, 'next_allowed_ms' => 0]);
                DB::table('telegram_dispatch_state')->where('name', $name)->where('next_allowed_ms', '<', $until)->update(['next_allowed_ms' => $until]);
            }
        }, 3);
    }
}
