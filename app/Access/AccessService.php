<?php

namespace App\Access;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

class AccessService
{
    public function issue(
        ?DateTimeInterface $expiresAt = null,
        ?DateTimeInterface $grantExpiresAt = null,
        ?string $operatorId = null,
    ): IssuedKey {
        $expiresAt = $expiresAt === null
            ? CarbonImmutable::instance(now())->addHours(max(1, (int) config('access.key_lifetime_hours', 24)))
            : CarbonImmutable::instance($expiresAt);
        $grantExpiresAt = $grantExpiresAt === null ? null : CarbonImmutable::instance($grantExpiresAt);

        if ($expiresAt->lessThanOrEqualTo(now()) || $grantExpiresAt?->lessThanOrEqualTo(now())) {
            throw new InvalidArgumentException('Expiry must be in the future.');
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $id = DB::transaction(function () use ($token, $expiresAt, $grantExpiresAt, $operatorId): int {
            $id = DB::table('access_keys')->insertGetId([
                'token_hash' => hash('sha256', $token),
                'expires_at' => $expiresAt,
                'grant_expires_at' => $grantExpiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit('key.issued', 'access_key', $id, $operatorId, [
                'expires_at' => $expiresAt->toIso8601String(),
                'grant_expires_at' => $grantExpiresAt?->toIso8601String(),
            ]);

            return $id;
        });

        return new IssuedKey($id, $token, $expiresAt, $grantExpiresAt);
    }

    public function activate(string $token, string|int $telegramUserId, ?int $updateId = null): ActivationResult
    {
        $telegramUserId = (string) $telegramUserId;
        if (! preg_match('/^[1-9][0-9]{0,18}$/D', $telegramUserId) || ($updateId !== null && $updateId < 0)) {
            throw new AccessDenied;
        }

        $limiter = 'access:activation:'.hash('sha256', $telegramUserId);
        if (RateLimiter::tooManyAttempts($limiter, max(1, (int) config('access.activation_attempts', 10)))) {
            throw new AccessDenied;
        }
        RateLimiter::hit($limiter, max(1, (int) config('access.activation_decay_seconds', 300)));

        if (! preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) {
            throw new AccessDenied;
        }

        $result = DB::transaction(function () use ($token, $telegramUserId, $updateId): ActivationResult {
            $hash = hash('sha256', $token);
            if ($updateId !== null) {
                $update = DB::table('telegram_updates')->where('update_id', $updateId)->first();
                if ($update !== null) {
                    $user = DB::table('users')->where('id', $update->user_id)->first();
                    $key = DB::table('access_keys')->where('id', $update->access_key_id)->first();
                    $grant = $user === null ? null : $this->activeGrantForKey((int) $user->id, (int) ($key?->id ?? 0));
                    if ($update->result !== 'activated' || $user?->telegram_user_id !== $telegramUserId
                        || $key === null || ! hash_equals($key->token_hash, $hash) || $grant === null) {
                        throw new AccessDenied;
                    }

                    return new ActivationResult((int) $user->id, (int) $grant->id, true);
                }
            }

            $key = DB::table('access_keys')->where('token_hash', $hash)
                ->whereNull('revoked_at')->whereNull('redeemed_at')
                ->where('expires_at', '>', now())
                ->where(fn (Builder $query) => $query->whereNull('grant_expires_at')->orWhere('grant_expires_at', '>', now()))
                ->first();
            if ($key === null) {
                throw new AccessDenied;
            }

            $user = DB::table('users')->where('telegram_user_id', $telegramUserId)->first();
            if ($user !== null && $user->state !== 'active') {
                throw new AccessDenied;
            }
            $userId = $user?->id ?? DB::table('users')->insertGetId([
                'telegram_user_id' => $telegramUserId,
                'state' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $consumed = DB::table('access_keys')->where('id', $key->id)
                ->whereNull('redeemed_at')->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->update(['redeemed_at' => now(), 'redeemed_by_user_id' => $userId, 'updated_at' => now()]);
            if ($consumed !== 1) {
                throw new AccessDenied;
            }

            $grantId = DB::table('access_grants')->insertGetId([
                'user_id' => $userId,
                'access_key_id' => $key->id,
                'expires_at' => $key->grant_expires_at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($updateId !== null) {
                DB::table('telegram_updates')->insert([
                    'update_id' => $updateId,
                    'user_id' => $userId,
                    'access_key_id' => $key->id,
                    'result' => 'activated',
                    'processed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('audit_events')->insert([
                'actor_user_id' => $userId,
                'action' => 'grant.activated',
                'subject_type' => 'access_grant',
                'subject_id' => (string) $grantId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return new ActivationResult((int) $userId, (int) $grantId);
        }, 3);

        RateLimiter::clear($limiter);

        return $result;
    }

    public function activeGrant(int $userId): ?object
    {
        return $this->grantQuery()->where('g.user_id', $userId)->orderByDesc('g.id')->first(['g.*']);
    }

    public function requireGrant(int $userId): object
    {
        return $this->activeGrant($userId) ?? throw new AccessDenied;
    }

    public function deliveryAuthorized(int $outboxId): bool
    {
        return $this->deliveryAuthorizations()->where('a.outbox_id', $outboxId)->exists();
    }

    public function revokeKey(int $keyId, ?string $operatorId = null): void
    {
        DB::transaction(function () use ($keyId, $operatorId): void {
            if (! DB::table('access_keys')->where('id', $keyId)->exists()) {
                throw new InvalidArgumentException('Access key not found.');
            }
            DB::table('access_keys')->where('id', $keyId)->update(['revoked_at' => now(), 'updated_at' => now()]);
            $grants = DB::table('access_grants')->where('access_key_id', $keyId)->pluck('id');
            foreach ($grants as $grantId) {
                $this->revokeGrant((int) $grantId, $operatorId);
            }
            $this->audit('key.revoked', 'access_key', $keyId, $operatorId);
        });
    }

    public function expireKey(int $keyId, ?string $operatorId = null): void
    {
        DB::transaction(function () use ($keyId, $operatorId): void {
            if (! DB::table('access_keys')->where('id', $keyId)->exists()) {
                throw new InvalidArgumentException('Access key not found.');
            }
            DB::table('access_keys')->where('id', $keyId)->update(['expires_at' => now(), 'updated_at' => now()]);
            $this->audit('key.expired', 'access_key', $keyId, $operatorId);
        });
    }

    public function revokeGrant(int $grantId, ?string $operatorId = null): void
    {
        DB::transaction(function () use ($grantId, $operatorId): void {
            if (! DB::table('access_grants')->where('id', $grantId)->exists()) {
                throw new InvalidArgumentException('Access grant not found.');
            }
            $outboxIds = DB::table('outbox_authorizations')->where('grant_id', $grantId)->distinct()->pluck('outbox_id');
            DB::table('access_grants')->where('id', $grantId)->update(['revoked_at' => now(), 'updated_at' => now()]);
            DB::table('outbox_authorizations')->where('grant_id', $grantId)->delete();
            foreach ($outboxIds as $outboxId) {
                if (! $this->deliveryAuthorized((int) $outboxId)) {
                    DB::table('notification_outbox')->where('id', $outboxId)
                        ->whereNotIn('status', ['sent', 'cancelled'])
                        ->update([
                            'status' => 'cancelled',
                            'lease_token' => null,
                            'lease_expires_at' => null,
                            'last_error' => 'Authorization revoked.',
                            'updated_at' => now(),
                        ]);
                }
            }
            $this->audit('grant.revoked', 'access_grant', $grantId, $operatorId);
        });
    }

    public function invalidateRestored(?string $operatorId = null): void
    {
        DB::transaction(function () use ($operatorId): void {
            DB::table('access_keys')->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
            DB::table('access_grants')->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
            DB::table('outbox_authorizations')->delete();
            DB::table('notification_outbox')->whereNotIn('status', ['sent', 'cancelled'])->update([
                'status' => 'cancelled',
                'lease_token' => null,
                'lease_expires_at' => null,
                'last_error' => 'Access invalidated after restore.',
                'updated_at' => now(),
            ]);
            $this->audit('access.restore_invalidated', 'environment', null, $operatorId);
        });
    }

    private function activeGrantForKey(int $userId, int $keyId): ?object
    {
        return $this->grantQuery()->where('g.user_id', $userId)->where('g.access_key_id', $keyId)->first(['g.*']);
    }

    private function grantQuery(): Builder
    {
        return DB::table('access_grants as g')
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->join('access_keys as k', 'k.id', '=', 'g.access_key_id')
            ->where('u.state', 'active')->whereNull('g.revoked_at')->whereNull('k.revoked_at')
            ->where(fn (Builder $query) => $query->whereNull('g.expires_at')->orWhere('g.expires_at', '>', now()));
    }

    private function deliveryAuthorizations(): Builder
    {
        return DB::table('outbox_authorizations as a')
            ->join('access_grants as g', fn ($join) => $join->on('g.id', '=', 'a.grant_id')->on('g.user_id', '=', 'a.user_id'))
            ->join('access_keys as k', 'k.id', '=', 'g.access_key_id')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->join('wallet_subscriptions as s', fn ($join) => $join->on('s.id', '=', 'a.subscription_id')->on('s.user_id', '=', 'a.user_id'))
            ->join('user_wallets as w', fn ($join) => $join->on('w.id', '=', 's.user_wallet_id')->on('w.user_id', '=', 'a.user_id'))
            ->join('notification_outbox as o', 'o.id', '=', 'a.outbox_id')
            ->join('chat_destinations as d', 'd.id', '=', 'o.destination_id')
            ->join('user_chat_destinations as p', fn ($join) => $join->on('p.user_id', '=', 'a.user_id')->on('p.destination_id', '=', 'o.destination_id'))
            ->whereColumn('s.destination_id', 'o.destination_id')
            ->where('u.state', 'active')->where('s.enabled', true)->where('w.enabled', true)
            ->where('d.enabled', true)->whereNull('d.suspended_at')
            ->where('p.enabled', true)->whereNull('p.revoked_at')
            ->whereNull('g.revoked_at')->whereNull('k.revoked_at')
            ->where(fn (Builder $query) => $query->whereNull('g.expires_at')->orWhere('g.expires_at', '>', now()));
    }

    private function audit(string $action, string $subjectType, ?int $subjectId, ?string $operatorId, array $metadata = []): void
    {
        if ($operatorId !== null && (strlen($operatorId) > 64 || preg_match('/[^\x20-\x7E]/', $operatorId))) {
            throw new InvalidArgumentException('Operator identifier must be at most 64 printable characters.');
        }
        DB::table('audit_events')->insert([
            'operator_id' => $operatorId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId === null ? null : (string) $subjectId,
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
