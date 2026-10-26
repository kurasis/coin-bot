<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Domain\Amount;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class OutboxRouter
{
    /** Must be called inside the transfer persistence transaction. */
    public function enqueue(int $transferId, ?int $onlyUserWalletId = null, bool $historical = false): void
    {
        $transfer = DB::table('transfers')->find($transferId);
        if ($transfer === null) {
            throw new ScanException('Cannot route an unknown transfer.');
        }
        foreach ($this->eligibleForTransfer($transfer, $onlyUserWalletId, $historical) as $route) {
            $outbox = DB::table('notification_outbox')->where('transfer_id', $transferId)
                ->where('destination_id', $route->destination_id)->where('notification_kind', 'transfer')->first();
            $outboxId = $outbox?->id ?? DB::table('notification_outbox')->insertGetId([
                'transfer_id' => $transferId, 'destination_id' => $route->destination_id,
                'notification_kind' => 'transfer', 'status' => 'pending', 'available_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            // Replaying history never resurrects an already delivered or cancelled notification.
            if ($outbox !== null && in_array($outbox->status, ['sent', 'cancelled'], true)) {
                continue;
            }
            $authorization = [
                'outbox_id' => $outboxId, 'user_id' => $route->user_id,
                'subscription_id' => $route->subscription_id, 'grant_id' => $route->grant_id,
                'historical_override' => $historical,
                'created_at' => now(), 'updated_at' => now(),
            ];
            DB::table('outbox_authorizations')->insertOrIgnore($authorization);
            if ($historical) {
                DB::table('outbox_authorizations')->where('outbox_id', $outboxId)
                    ->where('subscription_id', $route->subscription_id)->where('grant_id', $route->grant_id)
                    ->update(['historical_override' => true, 'updated_at' => now()]);
            }
        }
    }

    /** @return Collection<int, object> */
    public function eligibleForTransfer(object $transfer, ?int $onlyUserWalletId = null, bool $historical = false): Collection
    {
        if ($historical && $onlyUserWalletId === null) {
            throw new ScanException('Historical notification routing must target one owned wallet.');
        }

        return $this->routesForTransfer($transfer, $onlyUserWalletId, ! $historical);
    }

    private function routesForTransfer(object $transfer, ?int $onlyUserWalletId, bool $applyNotificationFloor): Collection
    {
        $query = $this->routeQuery()->where('w.chain', $transfer->chain)
            ->where('uw.start_block', '<=', $transfer->block_number)
            ->where(fn (Builder $q) => $q->where('w.address', $transfer->from_address)->orWhere('w.address', $transfer->to_address));
        if ($onlyUserWalletId !== null) {
            $query->where('uw.id', $onlyUserWalletId);
        }
        if ($applyNotificationFloor) {
            $query->where('s.notification_start_block', '<=', $transfer->block_number);
        }
        $amount = new Amount($transfer->amount_raw);

        return $query->get([
            's.id as subscription_id', 's.user_id', 's.destination_id', 's.direction', 's.min_amount', 's.notification_start_block',
            'uw.id as user_wallet_id', 'uw.label', 'w.address', 'g.id as grant_id',
            'd.chat_id', 'd.kind', 'd.approved_label', 'u.telegram_user_id',
        ])->filter(function (object $route) use ($transfer, $amount): bool {
            $incoming = $route->address === $transfer->to_address;
            $outgoing = $route->address === $transfer->from_address;
            $directionMatches = $route->direction === 'both'
                || ($route->direction === 'incoming' && $incoming)
                || ($route->direction === 'outgoing' && $outgoing);
            if (! $directionMatches || $amount->compareTo(new Amount($route->min_amount)) < 0) {
                return false;
            }
            if ($route->kind === 'private') {
                return (string) $route->chat_id === (string) $route->telegram_user_id;
            }

            return (bool) config('telegram.allow_shared_destinations', false);
        })->values();
    }

    /** Complete routing revalidation, including direction/amount and authorizing references. */
    public function authorizedRoutes(int $outboxId): Collection
    {
        $outbox = DB::table('notification_outbox')->find($outboxId);
        $transfer = $outbox === null ? null : DB::table('transfers')->find($outbox->transfer_id);
        if ($outbox === null || $transfer === null) {
            return collect();
        }
        $references = DB::table('outbox_authorizations')->where('outbox_id', $outboxId)->get();

        return $this->routesForTransfer($transfer, null, false)->filter(fn (object $route): bool => (int) $route->destination_id === (int) $outbox->destination_id
            && $references->contains(fn (object $authorization): bool => (int) $authorization->user_id === (int) $route->user_id
                && (int) $authorization->subscription_id === (int) $route->subscription_id
                && (int) $authorization->grant_id === (int) $route->grant_id
                && ((bool) $authorization->historical_override || $transfer->block_number >= $route->notification_start_block)))->values();
    }

    private function routeQuery(): Builder
    {
        return DB::table('wallet_subscriptions as s')
            ->join('user_wallets as uw', fn ($join) => $join->on('uw.id', 's.user_wallet_id')->on('uw.user_id', 's.user_id'))
            ->join('wallets as w', 'w.id', 'uw.wallet_id')
            ->join('users as u', 'u.id', 's.user_id')
            ->join('access_grants as g', 'g.user_id', 's.user_id')
            ->join('access_keys as k', 'k.id', 'g.access_key_id')
            ->join('chat_destinations as d', 'd.id', 's.destination_id')
            ->join('user_chat_destinations as p', fn ($join) => $join->on('p.user_id', 's.user_id')->on('p.destination_id', 's.destination_id'))
            ->where('u.state', 'active')->where('uw.enabled', true)->where('s.enabled', true)
            ->where('d.enabled', true)->whereNull('d.suspended_at')
            ->where('p.enabled', true)->whereNull('p.revoked_at')
            ->whereNull('g.revoked_at')->whereNull('k.revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('g.expires_at')->orWhere('g.expires_at', '>', now()));
    }
}
