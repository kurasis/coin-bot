<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Blockchain\Contracts\ChainClient;
use App\Blockchain\Exceptions\RpcException;
use App\Blockchain\Hex;
use App\Operations\MaintenanceGuard;
use Illuminate\Support\Facades\DB;

/** Observations only: this service never advances finalized coverage or changes its ledger. */
final class EthereumEarlyScanner
{
    public function __construct(
        private readonly BlockScanner $collector = new BlockScanner,
        private readonly OutboxRouter $router = new OutboxRouter,
    ) {}

    public function scan(ChainClient $client, int $maxRange = 100, ?ChainClient $witness = null): ScanResult
    {
        if (! config('ethereum.early_enabled', false)) {
            return new ScanResult(0, null, null, 0, true);
        }
        app(MaintenanceGuard::class)->assertRunning();
        if (app()->isDownForMaintenance() || $client->chain() !== 'ethereum' || ! method_exists($client, 'latestBlock')
            || $maxRange < 1 || $maxRange > 10000) {
            throw new ScanException('Early monitoring requires an enabled Ethereum client and a bounded range.');
        }
        $primary = $this->primary();
        $client->verifyNetwork();
        $latest = $this->header($client->latestBlock());
        $depth = max(1, min(64, (int) config('ethereum.confirmations', 6)));
        $height = max(0, $latest['number'] - $depth);
        $head = $this->header($client->block($height), $height);
        $state = DB::table('ethereum_early_state')->find(1);
        if ($state === null) {
            DB::transaction(function () use ($primary, $head): void {
                $this->assertWatchSet($primary);
                if (DB::table('ethereum_early_state')->exists()) {
                    throw new ScanException('Another worker initialized early coverage; retry.');
                }
                DB::table('ethereum_early_state')->insert([
                    'id' => 1, 'watch_set_id' => $primary->watch_set_id,
                    'start_block' => $head['number'] + 1, 'last_block' => $head['number'], 'last_block_hash' => $head['hash'],
                    'revision' => 0, 'reorg_pending' => false, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }, 3);

            return new ScanResult(1, null, null, 0, true);
        }
        if ($state->paused_at !== null) {
            throw new ScanException('Early coverage is paused and needs operator investigation.');
        }
        $previous = $this->header($client->block((int) $state->last_block), (int) $state->last_block);
        if ($previous['hash'] !== $state->last_block_hash || $state->reorg_pending) {
            $state = $this->recoverReorganization($client, $witness, $primary, $state);
        }
        $from = (int) $state->last_block + 1;
        $to = min($height, $from + $maxRange - 1);
        if ($from > $to) {
            return new ScanResult(1, null, null, 0, true);
        }
        $events = $this->collector->collectProvisionalEthereum($client, (int) $primary->watch_set_id, $from, $to, $height);
        $boundary = $this->header($client->block($to), $to);
        $stableHead = $to === $height ? $boundary : $this->header($client->block($height), $height);
        if ($stableHead['hash'] !== $head['hash']) {
            $this->markReorgPending($state);
            throw new ScanException('Early boundary changed during collection; observations were not committed.');
        }
        // Recheck the old boundary too: no partially observed reorg may be committed.
        if ($this->header($client->block((int) $state->last_block), (int) $state->last_block)['hash'] !== $state->last_block_hash) {
            $this->markReorgPending($state);
            throw new ScanException('Early history changed during collection; independent evidence is required.');
        }
        DB::transaction(function () use ($primary, $state, $events, $depth, $boundary): void {
            $this->assertSnapshot($primary, $state);
            foreach ($events as $event) {
                $identity = array_intersect_key($event, array_flip(['transaction_hash', 'receipt_log_position', 'block_hash']));
                $existing = DB::table('ethereum_early_events')->where($identity)->first();
                if ($existing !== null) {
                    foreach ($event as $column => $value) {
                        if ((string) $existing->{$column} !== (string) $value) {
                            throw new ScanException('An early observation changed without changing its block identity.');
                        }
                    }
                    if ($existing->state === 'orphaned') {
                        // Defer this oscillating observation to finality without
                        // blocking unrelated transfers in subsequent blocks.
                        continue;
                    }
                    $id = (int) $existing->id;
                } else {
                    $id = DB::table('ethereum_early_events')->insertGetId($event + [
                        'confirmations' => $depth, 'state' => 'observed', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $this->router->enqueueEarly($id);
            }
            DB::table('ethereum_early_state')->where('id', 1)->update([
                'watch_set_id' => $primary->watch_set_id, 'last_block' => $boundary['number'], 'last_block_hash' => $boundary['hash'],
                'revision' => (int) $state->revision + 1, 'updated_at' => now(),
            ]);
        }, 3);

        return new ScanResult(1, $from, $to, count($events));
    }

    private function recoverReorganization(ChainClient $client, ?ChainClient $witness, object $primary, object $state): object
    {
        if (! $state->reorg_pending) {
            $state = $this->markReorgPending($state);
        }
        if ($witness === null || $witness->chain() !== 'ethereum' || $witness->provider() === $client->provider()) {
            throw new ScanException('Early chain changes need an available independent provider; queued early messages are deferred.');
        }
        try {
            $witness->verifyNetwork();
        } catch (RpcException $error) {
            throw new EarlyWitnessException($witness->provider(), $error);
        }
        $current = $this->agree($client, $witness, (int) $state->last_block);
        $finalized = $this->header($client->finalizedBlock());
        $anchor = max((int) $state->start_block - 1, min((int) $primary->last_block, (int) $state->last_block, $finalized['number']));
        $window = max(1, min(1024, (int) config('ethereum.reorg_window', 128)));
        if ((int) $state->last_block - $anchor > $window) {
            $this->pause($state, 'Early reorganization exceeds the bounded recovery window.');
        }
        if ($primary->last_block_hash !== null && (int) $primary->last_block <= $finalized['number']) {
            $checkpoint = $this->agree($client, $witness, (int) $primary->last_block);
            if ($checkpoint['hash'] !== $primary->last_block_hash) {
                $this->pause($state, 'Previously finalized Ethereum coverage changed.');
            }
        }
        $anchorHeader = $anchor === (int) $state->last_block ? $current : $this->agree($client, $witness, $anchor);
        $observations = DB::table('ethereum_early_events')->where('state', 'observed')
            ->where('block_number', '<=', $state->last_block)->orderBy('block_number')->limit(10001)->get();
        if ($observations->count() > 10000
            || ($observations->isNotEmpty() && (int) $state->last_block - (int) $observations->first()->block_number > $window)) {
            $this->pause($state, 'Early reorganization evidence exceeds the bounded audit window.');
        }
        $headers = [];
        $orphaned = [];
        foreach ($observations as $event) {
            $height = (int) $event->block_number;
            $canonical = $headers[$height] ??= $this->agree($client, $witness, $height);
            if ($canonical['hash'] !== $event->block_hash) {
                $orphaned[] = (int) $event->id;
            }
        }
        DB::transaction(function () use ($primary, $state, $orphaned, $anchorHeader): void {
            $this->assertSnapshot($primary, $state);
            foreach ($orphaned as $id) {
                if (DB::table('ethereum_early_events')->where('id', $id)->where('state', 'finalized')->exists()) {
                    // Canonical truth changed while RPC evidence was gathered.
                    // Keep the already-durable delivery gate and retry against a
                    // fresh checkpoint instead of acting on a stale observation.
                    throw new ScanException('Canonical finalization changed during early reorganization verification; retry fresh evidence.');
                }
                $changed = DB::table('ethereum_early_events')->where('id', $id)->where('state', 'observed')->update(['state' => 'orphaned', 'updated_at' => now()]);
                if ($changed === 1) {
                    $this->router->orphanEarly($id);
                }
            }
            DB::table('ethereum_early_state')->where('id', 1)->update([
                'last_block' => $anchorHeader['number'], 'last_block_hash' => $anchorHeader['hash'],
                'watch_set_id' => $primary->watch_set_id, 'reorg_pending' => false,
                'revision' => (int) $state->revision + 1, 'updated_at' => now(),
            ]);
        }, 3);

        return DB::table('ethereum_early_state')->find(1);
    }

    private function markReorgPending(object $state): object
    {
        DB::transaction(function () use ($state): void {
            // A chain-change safety gate must also survive a concurrent wallet
            // edit. Membership is checked again before any event persistence.
            $current = DB::table('ethereum_early_state')->find(1);
            if ($current === null || $current->revision !== $state->revision || $current->last_block_hash !== $state->last_block_hash) {
                throw new ScanException('Another worker changed early coverage; retry its snapshot.');
            }
            DB::table('ethereum_early_state')->where('id', 1)->update([
                'reorg_pending' => true, 'revision' => (int) $state->revision + 1, 'updated_at' => now(),
            ]);
        }, 3);

        return DB::table('ethereum_early_state')->find(1);
    }

    private function primary(): object
    {
        $cursor = DB::table('scan_cursors')->where('chain', 'ethereum')->where('role', 'primary')->where('provider', 'primary')->first();
        if ($cursor === null || $cursor->watch_set_id === null || $cursor->paused_at !== null) {
            throw new ScanException('Authorized unpaused Ethereum monitoring must be initialized first.');
        }

        return $cursor;
    }

    private function assertWatchSet(object $primary): void
    {
        $current = $this->primary();
        if ($current->id !== $primary->id || $current->watch_set_id !== $primary->watch_set_id) {
            throw new ScanException('Ethereum wallet membership changed during collection; retry the new snapshot.');
        }
    }

    private function assertSnapshot(object $primary, object $state): void
    {
        $this->assertWatchSet($primary);
        $current = DB::table('ethereum_early_state')->find(1);
        if ($current === null || $current->revision !== $state->revision || $current->last_block_hash !== $state->last_block_hash || $current->paused_at !== null) {
            throw new ScanException('Another worker changed early coverage; retry the new snapshot.');
        }
    }

    private function header(array $header, ?int $expected = null): array
    {
        if (! is_int($header['number'] ?? null) || $header['number'] < 0
            || ($expected !== null && $header['number'] !== $expected)
            || ! is_int($header['timestamp'] ?? null) || $header['timestamp'] < 0) {
            throw new ScanException('Early canonical block header is invalid.');
        }
        $header['hash'] = Hex::data($header['hash'] ?? null, 32);

        return $header;
    }

    private function agree(ChainClient $client, ChainClient $witness, int $height): array
    {
        $first = $this->header($client->block($height), $height);
        try {
            $second = $this->header($witness->block($height), $height);
        } catch (RpcException $error) {
            throw new EarlyWitnessException($witness->provider(), $error);
        }
        if ($first['hash'] !== $second['hash']) {
            throw new ScanException('Independent providers disagree on an early chain change; no correction was committed.');
        }

        return $first;
    }

    private function pause(object $state, string $reason): never
    {
        DB::table('ethereum_early_state')->where('id', 1)->where('revision', $state->revision)->update([
            'paused_at' => now(), 'pause_reason' => $reason, 'reorg_pending' => true, 'updated_at' => now(),
        ]);
        throw new ScanException($reason);
    }
}
