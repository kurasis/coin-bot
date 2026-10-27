<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Domain\Amount;
use App\Telegram\TelegramAudience;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class OutboxRouter
{
    public function __construct(private TelegramAudience $audience = new TelegramAudience) {}

    /** Must be called inside the transfer persistence transaction. */
    public function enqueue(int $transferId, ?int $onlyUserWalletId = null, bool $historical = false): void
    {
        $transfer = DB::table('transfers')->find($transferId);
        if ($transfer === null) {
            throw new ScanException('Cannot route an unknown transfer.');
        }
        // Finalized inclusion also resolves obsolete observations when the early
        // worker was stopped: a transaction can reappear in a different block.
        foreach (DB::table('ethereum_early_events')->where('chain', $transfer->chain)
            ->where('transaction_hash', $transfer->transaction_hash)->where('receipt_log_position', $transfer->receipt_log_position)
            ->where('state', 'observed')->where('block_hash', '!=', $transfer->block_hash)->get() as $obsolete) {
            DB::table('ethereum_early_events')->where('id', $obsolete->id)->update([
                'state' => 'orphaned', 'canonical_transfer_id' => $transferId, 'updated_at' => now(),
            ]);
            $this->orphanEarly((int) $obsolete->id);
        }
        DB::table('ethereum_early_events')->where('chain', $transfer->chain)
            ->where('transaction_hash', $transfer->transaction_hash)->where('receipt_log_position', $transfer->receipt_log_position)
            ->where('state', 'orphaned')->whereNull('canonical_transfer_id')
            ->update(['canonical_transfer_id' => $transferId, 'updated_at' => now()]);
        $early = DB::table('ethereum_early_events')->where('chain', $transfer->chain)
            ->where('transaction_hash', $transfer->transaction_hash)->where('receipt_log_position', $transfer->receipt_log_position)
            ->where('block_hash', $transfer->block_hash)->first();
        $promote = false;
        if ($early !== null) {
            $same = true;
            foreach (['contract_address', 'from_address', 'to_address', 'amount_raw', 'block_number', 'block_timestamp'] as $column) {
                $same = $same && (string) $early->{$column} === (string) $transfer->{$column};
            }
            if ($same) {
                $promote = true;
                DB::table('ethereum_early_events')->where('id', $early->id)->update([
                    'state' => 'finalized', 'canonical_transfer_id' => $transferId, 'updated_at' => now(),
                ]);
                DB::table('notification_outbox')->where('early_event_id', $early->id)->where('notification_kind', 'reorg')
                    ->whereNotIn('status', ['sent', 'cancelled', 'processing'])->update([
                        'status' => 'cancelled', 'lease_token' => null, 'lease_expires_at' => null,
                        'last_error' => 'Canonical finalization superseded preliminary correction.', 'updated_at' => now(),
                    ]);
            } elseif ($early->state === 'observed') {
                DB::table('ethereum_early_events')->where('id', $early->id)->update(['state' => 'orphaned', 'updated_at' => now()]);
                $this->orphanEarly((int) $early->id);
            }
        }
        foreach ($this->eligibleForTransfer($transfer, $onlyUserWalletId, $historical) as $route) {
            $outbox = DB::table('notification_outbox')->where('transfer_id', $transferId)
                ->where('destination_id', $route->destination_id)->where('notification_kind', 'transfer')->first();
            // Retraction is recipient-specific: Alice's correction must never
            // create a duplicate finalized notification for an unaffected Bob.
            $earlyOutbox = $early !== null && $promote && ! $this->correctionPossiblySent((int) $early->id, (int) $route->destination_id)
                ? DB::table('notification_outbox')->where('early_event_id', $early->id)->where('destination_id', $route->destination_id)->where('notification_kind', 'transfer')->first()
                : null;
            if ($earlyOutbox !== null && $earlyOutbox->status === 'cancelled' && $earlyOutbox->sent_at === null
                && $earlyOutbox->last_error === 'Preliminary observation was invalidated.') {
                // Never resurrect a cancelled row; the canonical event is new
                // deliverable information when the original was never sent.
                $earlyOutbox = null;
            }
            if ($earlyOutbox !== null && $outbox === null) {
                DB::table('notification_outbox')->where('id', $earlyOutbox->id)->update(['transfer_id' => $transferId, 'updated_at' => now()]);
                $outbox = DB::table('notification_outbox')->find($earlyOutbox->id);
            } elseif ($earlyOutbox !== null && $outbox !== null && $earlyOutbox->id !== $outbox->id) {
                // Normally impossible under serialized persistence, but never send
                // an extra canonical copy if imported legacy state has both rows.
                $duplicate = $earlyOutbox->sent_at !== null ? $outbox : $earlyOutbox;
                DB::table('notification_outbox')->where('id', $duplicate->id)->whereNotIn('status', ['sent', 'cancelled'])->update([
                    'status' => 'cancelled', 'lease_token' => null, 'lease_expires_at' => null,
                    'last_error' => 'Duplicate preliminary/finalized notification suppressed.', 'updated_at' => now(),
                ]);
                if ($duplicate->id === $outbox->id) {
                    continue;
                }
            }
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

    /** Must be called in the early observation transaction. */
    public function enqueueEarly(int $eventId): void
    {
        $event = DB::table('ethereum_early_events')->find($eventId);
        if ($event === null || $event->state !== 'observed') {
            return;
        }
        $canonical = DB::table('transfers')->where('chain', 'ethereum')->where('transaction_hash', $event->transaction_hash)
            ->where('receipt_log_position', $event->receipt_log_position)->where('block_hash', $event->block_hash)->first();
        if ($canonical !== null) {
            $this->enqueue((int) $canonical->id);

            return;
        }
        foreach ($this->eligibleForTransfer($event) as $route) {
            $row = DB::table('notification_outbox')->where('early_event_id', $eventId)->where('destination_id', $route->destination_id)
                ->where('notification_kind', 'transfer')->first();
            $id = $row?->id ?? DB::table('notification_outbox')->insertGetId([
                'transfer_id' => null, 'early_event_id' => $eventId, 'destination_id' => $route->destination_id,
                'notification_kind' => 'transfer', 'status' => 'pending', 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($row !== null && in_array($row->status, ['sent', 'cancelled'], true)) {
                continue;
            }
            DB::table('outbox_authorizations')->insertOrIgnore([
                'outbox_id' => $id, 'user_id' => $route->user_id, 'subscription_id' => $route->subscription_id,
                'grant_id' => $route->grant_id, 'historical_override' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function transferForOutbox(object $outbox): ?object
    {
        if ($outbox->transfer_id !== null) {
            return DB::table('transfers')->find($outbox->transfer_id);
        }
        $event = ($outbox->early_event_id ?? null) === null ? null : DB::table('ethereum_early_events')->find($outbox->early_event_id);
        if ($event !== null && $event->state === 'finalized' && $event->canonical_transfer_id !== null && $outbox->notification_kind !== 'reorg') {
            return DB::table('transfers')->find($event->canonical_transfer_id);
        }

        return $event;
    }

    /** Cancel unsent observations; only proven/ambiguous deliveries need a correction. */
    public function orphanEarly(int $eventId): void
    {
        if (! DB::table('ethereum_early_events')->where('id', $eventId)->where('state', 'orphaned')->exists()) {
            return;
        }
        foreach (DB::table('notification_outbox')->where('early_event_id', $eventId)->where('notification_kind', 'transfer')->get() as $row) {
            $resolved = DB::table('delivery_attempts')->where('outbox_id', $row->id)->count();
            $inFlight = $row->status === 'processing' && (int) $row->attempts > $resolved;
            if (! $inFlight && ! in_array($row->status, ['sent', 'cancelled'], true)) {
                DB::table('notification_outbox')->where('id', $row->id)->update([
                    'status' => 'cancelled', 'lease_token' => null, 'lease_expires_at' => null,
                    'last_error' => 'Preliminary observation was invalidated.', 'updated_at' => now(),
                ]);
            }
            $this->enqueueEarlyCorrection((int) $row->id);
        }
    }

    public function enqueueEarlyCorrection(int $originalOutboxId): void
    {
        $row = DB::table('notification_outbox')->find($originalOutboxId);
        $event = ($row?->early_event_id ?? null) === null ? null : DB::table('ethereum_early_events')->find($row->early_event_id);
        if ($row === null || $event === null || $event->state !== 'orphaned' || $row->notification_kind !== 'transfer') {
            return;
        }
        $possiblySent = $row->sent_at !== null || DB::table('delivery_attempts')->where('outbox_id', $row->id)
            ->whereIn('outcome', ['sent', 'sent_after_cancel', 'ambiguous'])->exists();
        if (! $possiblySent) {
            return;
        }
        $correction = DB::table('notification_outbox')->where('early_event_id', $event->id)->where('destination_id', $row->destination_id)->where('notification_kind', 'reorg')->first();
        $id = $correction?->id ?? DB::table('notification_outbox')->insertGetId([
            'transfer_id' => null, 'early_event_id' => $event->id, 'destination_id' => $row->destination_id,
            'notification_kind' => 'reorg', 'status' => 'pending', 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (DB::table('outbox_authorizations')->where('outbox_id', $row->id)->get() as $authorization) {
            DB::table('outbox_authorizations')->insertOrIgnore([
                'outbox_id' => $id, 'user_id' => $authorization->user_id, 'subscription_id' => $authorization->subscription_id,
                'grant_id' => $authorization->grant_id, 'historical_override' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function correctionPossiblySent(int $eventId, int $destinationId): bool
    {
        $row = DB::table('notification_outbox')->where('early_event_id', $eventId)->where('destination_id', $destinationId)->where('notification_kind', 'reorg')->first();
        if ($row === null) {
            return false;
        }

        return $row->sent_at !== null || DB::table('delivery_attempts')->where('outbox_id', $row->id)->where('outcome', 'ambiguous')->exists()
            || ($row->status === 'processing' && (int) $row->attempts > DB::table('delivery_attempts')->where('outbox_id', $row->id)->count());
    }

    /** An obsolete correction accepted after finalization needs an authoritative follow-up. */
    public function settleFinalizedCorrection(int $correctionOutboxId): void
    {
        $correction = DB::table('notification_outbox')->find($correctionOutboxId);
        $event = ($correction?->early_event_id ?? null) === null ? null : DB::table('ethereum_early_events')->find($correction->early_event_id);
        if ($correction === null || $correction->notification_kind !== 'reorg' || $event === null || $event->canonical_transfer_id === null) {
            return;
        }
        $sent = $correction->sent_at !== null || DB::table('delivery_attempts')->where('outbox_id', $correction->id)->where('outcome', 'ambiguous')->exists();
        if (! $sent) {
            return;
        }
        $transfer = DB::table('transfers')->find($event->canonical_transfer_id);
        if ($transfer === null) {
            return;
        }
        $normal = DB::table('notification_outbox')->where('transfer_id', $transfer->id)->where('destination_id', $correction->destination_id)
            ->where('notification_kind', 'transfer')->first();
        if ($normal !== null && $normal->sent_at === null
            && ! DB::table('delivery_attempts')->where('outbox_id', $normal->id)->where('outcome', 'ambiguous')->exists()) {
            if ($normal->status !== 'processing' || (int) $normal->attempts === DB::table('delivery_attempts')->where('outbox_id', $normal->id)->count()) {
                // An existing unsent canonical delivery will follow the now
                // resolved correction. Cancelled/failed work is never revived.
                return;
            }
        }
        foreach ($this->eligibleForTransfer($transfer)->where('destination_id', $correction->destination_id) as $route) {
            $existing = DB::table('notification_outbox')->where('transfer_id', $transfer->id)->where('destination_id', $route->destination_id)->where('notification_kind', 'finalization')->first();
            $id = $existing?->id ?? DB::table('notification_outbox')->insertGetId([
                'transfer_id' => $transfer->id, 'early_event_id' => $event->id, 'destination_id' => $route->destination_id,
                'notification_kind' => 'finalization', 'status' => 'pending', 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($existing !== null && in_array($existing->status, ['sent', 'cancelled'], true)) {
                continue;
            }
            DB::table('outbox_authorizations')->insertOrIgnore([
                'outbox_id' => $id, 'user_id' => $route->user_id, 'subscription_id' => $route->subscription_id,
                'grant_id' => $route->grant_id, 'historical_override' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
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
            if (! $directionMatches || $amount->compareTo(new Amount($route->min_amount)) < 0
                || ! $this->audience->allows((string) $route->telegram_user_id)) {
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
        $transfer = $outbox === null ? null : $this->transferForOutbox($outbox);
        if ($outbox === null || $transfer === null) {
            return collect();
        }
        if ($outbox->transfer_id === null) {
            if (($outbox->notification_kind === 'reorg' && ($transfer->state ?? null) !== 'orphaned')
                || ($outbox->notification_kind !== 'reorg' && ($transfer->state ?? null) === 'orphaned')) {
                return collect();
            }
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
