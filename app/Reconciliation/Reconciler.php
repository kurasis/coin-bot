<?php

declare(strict_types=1);

namespace App\Reconciliation;

use App\Blockchain\Contracts\ChainClient;
use App\Blockchain\Exceptions\RpcException;
use App\Blockchain\Hex;
use App\Monitoring\BlockScanner;
use App\Monitoring\OutboxRouter;
use App\Monitoring\ScanException;
use App\Monitoring\TransferDecoder;
use App\Operations\MaintenanceGuard;
use Illuminate\Support\Facades\DB;

final class Reconciler
{
    private const EVENT_COLUMNS = ['chain', 'transaction_hash', 'receipt_log_position', 'contract_address', 'from_address', 'to_address', 'amount_raw', 'block_number', 'block_hash', 'block_timestamp', 'finality'];

    public function __construct(
        private readonly BlockScanner $scanner = new BlockScanner,
        private readonly OutboxRouter $router = new OutboxRouter,
        private readonly TransferDecoder $decoder = new TransferDecoder,
        private readonly ProviderCircuitBreaker $circuits = new ProviderCircuitBreaker,
    ) {}

    public function reconcile(ChainClient $primary, ChainClient $secondary): ReconciliationResult
    {
        if (class_exists(MaintenanceGuard::class)) {
            app(MaintenanceGuard::class)->assertRunning();
        }
        if ($primary->chain() !== $secondary->chain() || $primary->provider() === $secondary->provider()) {
            throw new ScanException('Reconciliation requires independent provider roles for the same chain.');
        }
        $live = DB::table('scan_cursors')->where('chain', $primary->chain())->where('role', 'primary')->where('provider', $primary->provider())->first();
        if ($live === null) {
            return new ReconciliationResult(idle: true);
        }
        if ($live->paused_at !== null) {
            throw new ScanException('Primary coverage is paused; reconciliation requires investigation.');
        }
        $watermark = DB::table('scan_cursors')->where('chain', $primary->chain())->where('role', 'reconciliation')->where('provider', $secondary->provider())->first();
        if ($watermark?->paused_at !== null) {
            throw new ScanException('Reconciliation coverage is paused.');
        }
        $range = $this->nextRange($live, $watermark);
        if ($range === null) {
            return new ReconciliationResult(idle: true);
        }
        $set = DB::table('watch_sets')->where('id', $range->watch_set_id)->where('chain', $primary->chain())->whereNotNull('activated_at')->first();
        if ($set === null) {
            throw new ScanException('Private backfill snapshots cannot be treated as live reconciliation coverage.');
        }
        foreach ([$primary, $secondary] as $provider) {
            $floor = config('blockchain.chains.'.$provider->chain().'.providers.'.$provider->provider().'.history_start_block');
            $minimum = $floor === null ? false : filter_var($floor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($minimum === false || (int) $range->from_block < $minimum) {
                return new ReconciliationResult((int) $range->id, waitingForHistory: true);
            }
        }
        $this->circuits->assertAvailable($primary);
        $this->circuits->assertAvailable($secondary);
        $primaryCached = new CachedChainClient($primary);
        $secondaryCached = new CachedChainClient($secondary);
        $primaryHead = $this->read($primary, function () use ($primaryCached): array {
            $primaryCached->verifyNetwork();

            return $primaryCached->finalizedBlock();
        });
        $secondaryHead = $this->read($secondary, function () use ($secondaryCached): array {
            $secondaryCached->verifyNetwork();

            return $secondaryCached->finalizedBlock();
        });
        if ($range->to_block > ($secondaryHead['number'] ?? -1) || $range->to_block > ($primaryHead['number'] ?? -1)) {
            return new ReconciliationResult((int) $range->id, waitingForFinality: true);
        }
        $primaryBoundary = $this->read($primary, fn (): array => $primaryCached->block((int) $range->to_block));
        $boundary = $this->read($secondary, fn (): array => $secondaryCached->block((int) $range->to_block));
        if (($primaryBoundary['hash'] ?? null) !== $range->to_block_hash || ($boundary['hash'] ?? null) !== $range->to_block_hash) {
            $this->conflict($live, $watermark, $range, $secondary, 'boundary_hash_mismatch');
        }
        if ($watermark?->last_block_hash !== null) {
            $previous = $this->read($secondary, fn (): array => $secondaryCached->block((int) $watermark->last_block));
            if (($previous['hash'] ?? null) !== $watermark->last_block_hash) {
                $this->conflict($live, $watermark, $range, $secondary, 'reconciliation_boundary_changed');
            }
        }
        $events = $this->read($secondary, fn (): array => $this->scanner->collect($secondaryCached, (int) $range->watch_set_id, (int) $range->from_block, (int) $range->to_block));
        $watched = DB::table('watch_set_wallets as m')->join('wallets as w', 'w.id', 'm.wallet_id')
            ->where('m.watch_set_id', $range->watch_set_id)->where('w.chain', $primary->chain())->pluck('w.address')->all();
        $known = DB::table('transfers')->where('chain', $primary->chain())
            ->whereBetween('block_number', [$range->from_block, $range->to_block])
            ->where(fn ($q) => $q->whereIn('from_address', $watched)->orWhereIn('to_address', $watched))
            ->get(self::EVENT_COLUMNS)->map(fn (object $row): array => (array) $row)->all();
        $knownMap = $this->map($known);
        $observedMap = $this->map($events);
        $witnessTransactions = [];
        foreach ($knownMap + $observedMap as $key => $event) {
            if (! isset($knownMap[$key], $observedMap[$key]) || BlockScanner::digest([$knownMap[$key]]) !== BlockScanner::digest([$observedMap[$key]])) {
                $witnessTransactions[$event['transaction_hash']] = true;
            }
        }
        $omissions = 0;
        foreach (array_keys($witnessTransactions) as $hash) {
            try {
                $primaryWitness = $this->read($primary, fn (): array => $this->witness($primaryCached, $hash, $watched, $range));
                $secondaryWitness = $this->read($secondary, fn (): array => $this->witness($secondaryCached, $hash, $watched, $range));
            } catch (RpcException $error) {
                if ($error->retryable) {
                    throw $error; // Unavailable evidence is not evidence of a conflicting transaction.
                }
                $this->conflict($live, $watermark, $range, $secondary, 'invalid_receipt_witness');
            } catch (ScanException) {
                $this->conflict($live, $watermark, $range, $secondary, 'invalid_receipt_witness');
            }
            if (BlockScanner::digest($primaryWitness) !== BlockScanner::digest($secondaryWitness)) {
                $this->conflict($live, $watermark, $range, $secondary, 'receipt_witness_disagreement');
            }
            $witnessMap = $this->map($primaryWitness);
            foreach ($knownMap + $observedMap as $key => $event) {
                if ($event['transaction_hash'] === $hash && (! isset($witnessMap[$key]) || BlockScanner::digest([$event]) !== BlockScanner::digest([$witnessMap[$key]]))) {
                    $this->conflict($live, $watermark, $range, $secondary, 'stored_event_witness_disagreement');
                }
            }
            foreach ($witnessMap as $key => $event) {
                if (! isset($observedMap[$key])) {
                    $omissions++;
                }
                $observedMap[$key] = $event;
            }
        }
        $canonical = array_values($observedMap);
        $digest = BlockScanner::digest($canonical);
        $repaired = count(array_diff_key($observedMap, $knownMap));
        $this->persist($live, $watermark, $range, $secondary, $canonical, $digest, $repaired, $omissions);
        $this->circuits->succeeded($primary);
        $this->circuits->succeeded($secondary);

        return new ReconciliationResult((int) $range->id, $repaired, $omissions);
    }

    private function nextRange(object $live, ?object $watermark): ?object
    {
        $ranges = DB::table('scan_ranges')->where('cursor_id', $live->id)->where('status', 'completed');
        $next = (clone $ranges)->whereNull('reconciled_at')->orderBy('from_block')->first();
        if ($next !== null) {
            if ($watermark !== null && (int) $next->from_block !== (int) $watermark->last_block + 1) {
                throw new ScanException('Reconciliation coverage has a gap; the watermark cannot jump over it.');
            }

            return $next;
        }
        if ($watermark === null) {
            return null;
        }
        $delay = max(60, (int) config('reconciliation.recheck_after_seconds', 900));
        $overlap = max(1, (int) config('reconciliation.overlap_blocks.'.$live->chain, 7200));

        return $ranges->where('to_block', '>=', max(0, (int) $live->last_block - $overlap))
            ->where('reconciled_at', '<=', now()->subSeconds($delay))->orderBy('reconciled_at')->orderBy('from_block')->first();
    }

    private function witness(CachedChainClient $client, string $hash, array $watched, object $range): array
    {
        $receipt = $client->receipt($hash);
        if (Hex::integer($receipt['status'] ?? null) !== 1 || ! is_array($receipt['logs'] ?? null)) {
            throw new ScanException('Receipt witness does not confirm successful execution.');
        }
        $candidates = array_map(static fn (array $log): array => $log + [
            'transactionHash' => $receipt['transactionHash'], 'blockHash' => $receipt['blockHash'], 'blockNumber' => $receipt['blockNumber'],
        ], $receipt['logs']);

        return $this->decoder->decode($client, $candidates, $watched, (int) $range->from_block, (int) $range->to_block);
    }

    private function persist(object $live, ?object $watermark, object $range, ChainClient $secondary, array $events, string $digest, int $repaired, int $omissions): void
    {
        DB::transaction(function () use ($live, $watermark, $range, $secondary, $events, $digest, $repaired, $omissions): void {
            $current = DB::table('scan_ranges')->find($range->id);
            if ($current === null || $current->status !== 'completed' || $current->watch_set_id !== $range->watch_set_id || $current->to_block_hash !== $range->to_block_hash
                || DB::table('scan_cursors')->where('id', $live->id)->whereNotNull('paused_at')->exists()) {
                throw new ScanException('Live coverage changed or paused during reconciliation.');
            }
            $identity = ['chain' => $live->chain, 'role' => 'reconciliation', 'provider' => $secondary->provider()];
            $cursor = DB::table('scan_cursors')->where($identity)->first();
            if (($watermark === null && $cursor !== null) || ($watermark !== null && ($cursor === null || $cursor->last_block !== $watermark->last_block || $cursor->paused_at !== null))) {
                throw new ScanException('Another worker changed reconciliation coverage; retry the new snapshot.');
            }
            if ($cursor === null) {
                $cursorId = DB::table('scan_cursors')->insertGetId($identity + [
                    'watch_set_id' => $range->watch_set_id, 'last_block' => $range->from_block - 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $cursor = DB::table('scan_cursors')->find($cursorId);
            }
            foreach ($events as $event) {
                $key = array_intersect_key($event, array_flip(['chain', 'transaction_hash', 'receipt_log_position']));
                $existing = DB::table('transfers')->where($key)->first();
                if ($existing === null) {
                    $id = DB::table('transfers')->insertGetId($event + ['created_at' => now(), 'updated_at' => now()]);
                } else {
                    foreach ($event as $column => $value) {
                        if ((string) $existing->{$column} !== (string) $value) {
                            throw new ScanException('An immutable transfer changed during reconciliation persistence.');
                        }
                    }
                    $id = (int) $existing->id;
                }
                // Normal routing preserves user notification floors; reconciliation never opts into historical delivery.
                $this->router->enqueue($id);
            }
            if ((int) $range->to_block > (int) $cursor->last_block) {
                if ((int) $range->from_block !== (int) $cursor->last_block + 1) {
                    throw new ScanException('Reconciliation cannot advance across a coverage gap.');
                }
                DB::table('scan_cursors')->where('id', $cursor->id)->update([
                    'last_block' => $range->to_block, 'last_block_hash' => $range->to_block_hash,
                    'watch_set_id' => $range->watch_set_id, 'updated_at' => now(),
                ]);
            }
            $secondaryRange = ['cursor_id' => $cursor->id, 'watch_set_id' => $range->watch_set_id, 'from_block' => $range->from_block, 'to_block' => $range->to_block];
            DB::table('scan_ranges')->updateOrInsert($secondaryRange, [
                'to_block_hash' => $range->to_block_hash, 'event_digest' => $digest, 'status' => 'completed',
                'completed_at' => now(), 'reconciled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('scan_ranges')->where('id', $range->id)->update(['event_digest' => $digest, 'reconciled_at' => now(), 'updated_at' => now()]);
            DB::table('reconciliation_checks')->updateOrInsert(['range_id' => $range->id, 'provider' => $secondary->provider()], [
                'status' => $repaired > 0 ? 'repaired' : 'matched', 'observed_digest' => $digest,
                'repaired_events' => $repaired, 'index_omissions' => $omissions, 'failure_code' => null,
                'last_checked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    private function conflict(object $live, ?object $watermark, object $range, ChainClient $secondary, string $code): never
    {
        DB::transaction(function () use ($live, $watermark, $range, $secondary, $code): void {
            DB::table('scan_cursors')->where('id', $live->id)->update(['paused_at' => now(), 'pause_reason' => 'Provider reconciliation conflict: '.$code, 'updated_at' => now()]);
            if ($watermark !== null) {
                DB::table('scan_cursors')->where('id', $watermark->id)->update(['paused_at' => now(), 'pause_reason' => 'Provider reconciliation conflict: '.$code, 'updated_at' => now()]);
            }
            DB::table('reconciliation_checks')->updateOrInsert(['range_id' => $range->id, 'provider' => $secondary->provider()], [
                'status' => 'conflict', 'failure_code' => $code, 'last_checked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'action' => 'reconciliation.conflict', 'subject_type' => 'scan_range', 'subject_id' => (string) $range->id,
                'metadata' => json_encode(['chain' => $live->chain, 'code' => $code], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        throw new ScanException('Provider evidence conflicts; coverage is paused and existing events are preserved.');
    }

    private function read(ChainClient $provider, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (RpcException $error) {
            $this->circuits->failed($provider, $error);
            throw $error;
        }
    }

    private function map(array $events): array
    {
        $result = [];
        foreach ($events as $event) {
            $ordered = [];
            foreach (self::EVENT_COLUMNS as $column) {
                $ordered[$column] = $event[$column];
            }
            $result[$event['transaction_hash'].':'.$event['receipt_log_position']] = $ordered;
        }

        return $result;
    }
}
