<?php

namespace App\Telegram;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class QueueLeases
{
    public function claim(string $table): ?object
    {
        return DB::transaction(function () use ($table): ?object {
            $row = DB::table($table)->where(function (Builder $query): void {
                $query->where(function (Builder $ready): void {
                    $ready->whereIn('status', ['pending', 'retry'])->where(fn (Builder $q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()));
                })->orWhere(function (Builder $expired): void {
                    $expired->where('status', 'processing')->where('lease_expires_at', '<=', now());
                });
            })->orderBy('id')->first();
            if ($row === null) {
                return null;
            }
            $token = bin2hex(random_bytes(24));
            $seconds = $this->leaseSeconds();
            DB::table($table)->where('id', $row->id)->update([
                'status' => 'processing', 'lease_token' => $token,
                'lease_expires_at' => now()->addSeconds($seconds), 'updated_at' => now(),
            ]);

            return DB::table($table)->find($row->id);
        }, 3);
    }

    public function beginAttempt(string $table, object $row): bool
    {
        return $this->owned($table, $row)->where('lease_expires_at', '>', now())->update([
            'attempts' => DB::raw('attempts + 1'),
            'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
            'updated_at' => now(),
        ]) === 1;
    }

    public function release(string $table, object $row, string $status, ?string $error = null, int $delaySeconds = 0): void
    {
        $this->owned($table, $row)->update([
            'status' => $status, 'last_error' => $error,
            'available_at' => now()->addSeconds(max(0, $delaySeconds)),
            'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now(),
        ]);
    }

    public function success(string $table, object $row, string $messageId): void
    {
        $this->owned($table, $row)->update([
            'status' => 'sent', 'telegram_message_id' => $messageId, 'sent_at' => now(),
            'last_error' => null, 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now(),
        ]);
    }

    public function owned(string $table, object $row): Builder
    {
        return DB::table($table)->where('id', $row->id)->where('status', 'processing')->where('lease_token', $row->lease_token);
    }

    private function leaseSeconds(): int
    {
        return max(
            (int) config('telegram.request_timeout', 15) + 15,
            max(1, min(60, (int) config('telegram.connect_timeout', 15))) + 25,
            (int) config('telegram.lease_seconds', 90),
        );
    }
}
