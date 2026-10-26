<?php

declare(strict_types=1);

namespace App\Reconciliation;

use App\Blockchain\Contracts\ChainClient;
use App\Blockchain\Exceptions\RpcException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ProviderCircuitBreaker
{
    public function assertAvailable(ChainClient $client): void
    {
        $state = DB::table('provider_health')->where('chain', $client->chain())->where('provider', $client->provider())->first();
        if ($state?->opens_until !== null && CarbonImmutable::parse($state->opens_until)->isFuture()) {
            throw new ProviderCoolingDown(max(1, (int) ceil(now()->diffInSeconds(CarbonImmutable::parse($state->opens_until)))));
        }
    }

    public function succeeded(ChainClient $client): void
    {
        DB::transaction(function () use ($client): void {
            $identity = ['chain' => $client->chain(), 'provider' => $client->provider()];
            $state = DB::table('provider_health')->where($identity)->first();
            $values = ['last_success_at' => now(), 'created_at' => $state?->created_at ?? now(), 'updated_at' => now()];
            // An older concurrent success cannot cancel a still-mandatory Retry-After.
            if ($state?->opens_until === null || ! CarbonImmutable::parse($state->opens_until)->isFuture()) {
                $values += ['failure_count' => 0, 'opens_until' => null, 'last_http_status' => null];
            }
            DB::table('provider_health')->updateOrInsert($identity, $values);
        });
    }

    public function failed(ChainClient $client, RpcException $error): void
    {
        if (! $error->retryable) {
            return;
        }
        DB::transaction(function () use ($client, $error): void {
            $identity = ['chain' => $client->chain(), 'provider' => $client->provider()];
            $state = DB::table('provider_health')->where($identity)->first();
            $failures = min(16, (int) ($state?->failure_count ?? 0) + 1);
            $base = max(1, (int) config('reconciliation.circuit_base_seconds', 15));
            $maximum = max($base, (int) config('reconciliation.circuit_max_seconds', 300));
            $cooldown = max(min($maximum, $base * (2 ** min(10, $failures - 1))), $error->retryAfterSeconds ?? 0);
            $opensUntil = now()->addSeconds($cooldown);
            if ($state?->opens_until !== null && CarbonImmutable::parse($state->opens_until)->greaterThan($opensUntil)) {
                $opensUntil = CarbonImmutable::parse($state->opens_until);
            }
            DB::table('provider_health')->updateOrInsert($identity, [
                'failure_count' => $failures, 'opens_until' => $opensUntil,
                'last_http_status' => $error->httpStatus, 'last_failure_at' => now(),
                'created_at' => $state?->created_at ?? now(), 'updated_at' => now(),
            ]);
        });
    }
}
