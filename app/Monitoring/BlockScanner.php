<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Blockchain\AddressCodec;
use App\Blockchain\Contracts\ChainClient;
use App\Blockchain\EthereumRpcClient;
use App\Blockchain\Exceptions\RangeLimitException;
use App\Blockchain\Hex;
use App\Blockchain\QueryPayloadLimitException;
use App\Operations\MaintenanceGuard;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BlockScanner
{
    public function __construct(
        private readonly TransferDecoder $decoder = new TransferDecoder,
        private readonly OutboxRouter $router = new OutboxRouter,
    ) {}

    public function initialize(string $chain, int $startBlock, int $watchSetId, string $role = 'primary', string $provider = 'primary'): int
    {
        if (! in_array($chain, ['ethereum', 'tron'], true) || $startBlock < 0) {
            throw new InvalidArgumentException('An explicit nonnegative start block and supported chain are required.');
        }
        $this->watchSet($chain, $watchSetId);

        return DB::transaction(function () use ($chain, $startBlock, $watchSetId, $role, $provider): int {
            if (DB::table('scan_cursors')->where(compact('chain', 'role', 'provider'))->exists()) {
                throw new ScanException('The scan cursor already exists; initialization never resets progress.');
            }

            return DB::table('scan_cursors')->insertGetId([
                'chain' => $chain, 'role' => $role, 'provider' => $provider,
                'watch_set_id' => $watchSetId, 'last_block' => $startBlock - 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function scan(ChainClient $client, int $maxRange = 100, string $role = 'primary'): ScanResult
    {
        $cursor = DB::table('scan_cursors')->where('chain', $client->chain())
            ->where('role', $role)->where('provider', $client->provider())->first();
        if ($cursor === null) {
            throw new ScanException('No explicit initialized scan cursor exists for this chain and provider.');
        }

        return $this->scanCursor($client, (int) $cursor->id, $maxRange);
    }

    public function scanCursor(
        ChainClient $client,
        int $cursorId,
        int $maxRange = 100,
        bool $notify = true,
        ?int $onlyUserWalletId = null,
        ?int $endBlock = null,
        bool $historical = false,
    ): ScanResult {
        if (app()->isDownForMaintenance()) {
            throw new ScanException('Scanning is disabled during maintenance.');
        }
        if (class_exists(MaintenanceGuard::class)) {
            app(MaintenanceGuard::class)->assertRunning();
        }
        if ($maxRange < 1 || $maxRange > 10000) {
            throw new InvalidArgumentException('Scan range must be between 1 and 10000 blocks.');
        }
        $cursor = DB::table('scan_cursors')->find($cursorId);
        if ($cursor === null || $cursor->chain !== $client->chain() || $cursor->watch_set_id === null) {
            throw new ScanException('Cursor does not reference this chain and an explicit watch set.');
        }
        if ($historical && ($cursor->role !== 'backfill' || $onlyUserWalletId === null || ! $notify)) {
            throw new ScanException('Historical notification override requires an explicitly scoped notifying backfill.');
        }
        if ($cursor->paused_at !== null) {
            throw new ScanException('This scan cursor is paused and requires operator investigation.');
        }
        $this->watchSet($client->chain(), (int) $cursor->watch_set_id);
        $client->verifyNetwork();
        $head = $client->finalizedBlock();
        $this->validateHeader($head);
        if ($cursor->last_block_hash !== null && $cursor->last_block >= 0) {
            $previous = $client->block((int) $cursor->last_block);
            if ($previous['hash'] !== $cursor->last_block_hash) {
                $this->pauseBoundaryMismatch($cursor);
                throw new ScanException('Finalized boundary hash changed; scanning has been paused.');
            }
        }
        $from = (int) $cursor->last_block + 1;
        $to = min($head['number'], $from + $maxRange - 1, $endBlock ?? PHP_INT_MAX);
        if ($from > $to) {
            return new ScanResult($cursorId, null, null, 0, true);
        }
        $events = $this->collectVerified($client, (int) $cursor->watch_set_id, $from, $to);
        $boundary = $client->block($to);
        $this->validateHeader($boundary);
        if ($boundary['number'] !== $to || ($head['number'] === $to && $head['hash'] !== $boundary['hash'])) {
            throw new ScanException('Finalized range boundary changed during collection.');
        }
        $digest = self::digest($events);

        DB::transaction(function () use ($cursor, $from, $to, $events, $boundary, $digest, $notify, $onlyUserWalletId, $historical): void {
            // Compare the immutable snapshot after HTTP work; another worker or a watch-set cutover wins safely.
            $changed = DB::table('scan_cursors')->where('id', $cursor->id)
                ->where('last_block', $cursor->last_block)->where('last_block_hash', $cursor->last_block_hash)
                ->where('watch_set_id', $cursor->watch_set_id)->whereNull('paused_at')
                ->update(['last_block' => $to, 'last_block_hash' => $boundary['hash'], 'updated_at' => now()]);
            if ($changed !== 1) {
                throw new ScanException('Cursor changed while the range was being collected; retry the current snapshot.');
            }
            foreach ($events as $event) {
                $identity = array_intersect_key($event, array_flip(['chain', 'transaction_hash', 'receipt_log_position']));
                $existing = DB::table('transfers')->where($identity)->first();
                if ($existing !== null) {
                    foreach ($event as $column => $value) {
                        if ((string) $existing->{$column} !== (string) $value) {
                            throw new ScanException('An immutable transfer disagrees with an existing event.');
                        }
                    }
                    $id = (int) $existing->id;
                } else {
                    $id = DB::table('transfers')->insertGetId($event + ['created_at' => now(), 'updated_at' => now()]);
                }
                if ($notify) {
                    $this->router->enqueue($id, $onlyUserWalletId, $historical);
                }
            }
            $rangeIdentity = ['cursor_id' => $cursor->id, 'watch_set_id' => $cursor->watch_set_id, 'from_block' => $from, 'to_block' => $to];
            $existingRange = DB::table('scan_ranges')->where($rangeIdentity)->first();
            if ($existingRange !== null && $existingRange->event_digest !== null && $existingRange->event_digest !== $digest) {
                throw new ScanException('The same completed coverage range has changed.');
            }
            DB::table('scan_ranges')->updateOrInsert($rangeIdentity, [
                'to_block_hash' => $boundary['hash'], 'event_digest' => $digest, 'status' => 'completed',
                'completed_at' => now(), 'created_at' => $existingRange?->created_at ?? now(), 'updated_at' => now(),
            ]);
        });

        return new ScanResult($cursorId, $from, $to, count($events));
    }

    /** Read-only normalized events for independent reconciliation or bounded backfills. */
    public function collect(ChainClient $client, int $watchSetId, int $from, int $to): array
    {
        $client->verifyNetwork();
        $head = $client->finalizedBlock();
        $this->validateHeader($head);
        if ($from < 0 || $to < $from || $to > $head['number']) {
            throw new ScanException('Requested range is outside this provider\'s finalized history.');
        }

        return $this->collectVerified($client, $watchSetId, $from, $to);
    }

    /** Explicitly provisional Ethereum observations; never written to finalized coverage. */
    public function collectProvisionalEthereum(ChainClient $client, int $watchSetId, int $from, int $to, int $confirmedHead): array
    {
        if ($client->chain() !== 'ethereum' || $from < 0 || $to < $from || $to > $confirmedHead) {
            throw new ScanException('Provisional collection must remain within its observed Ethereum confirmation boundary.');
        }

        return array_map(static fn (array $event): array => array_replace($event, ['finality' => 'preliminary']),
            $this->collectVerified($client, $watchSetId, $from, $to));
    }

    public static function digest(array $events): string
    {
        $normalized = array_map(static fn (array $event): array => array_intersect_key($event, array_flip([
            'chain', 'transaction_hash', 'receipt_log_position', 'contract_address', 'from_address', 'to_address',
            'amount_raw', 'block_number', 'block_hash', 'block_timestamp', 'finality',
        ])), $events);
        usort($normalized, static fn (array $a, array $b): int => [$a['block_number'], $a['transaction_hash'], $a['receipt_log_position']] <=> [$b['block_number'], $b['transaction_hash'], $b['receipt_log_position']]);

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    private function collectVerified(ChainClient $client, int $watchSetId, int $from, int $to): array
    {
        $this->watchSet($client->chain(), $watchSetId);
        $floor = config('blockchain.chains.'.$client->chain().'.providers.'.$client->provider().'.history_start_block');
        if ($floor !== null) {
            $minimum = filter_var($floor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($minimum === false || $from < $minimum) {
                throw new ScanException('Requested range is outside the configured provider history boundary.');
            }
        }
        $watched = DB::table('watch_set_wallets as m')->join('wallets as w', 'w.id', 'm.wallet_id')
            ->where('m.watch_set_id', $watchSetId)->where('w.chain', $client->chain())->orderBy('w.id')->pluck('w.address')->all();
        $candidates = [];
        $batchSize = max(1, (int) config('blockchain.chains.'.$client->chain().'.providers.'.$client->provider().'.max_topic_addresses', 100));
        foreach (array_chunk($watched, $batchSize) as $batch) {
            foreach (['outgoing', 'incoming'] as $direction) {
                array_push($candidates, ...$this->query($client, $from, $to, $batch, $direction));
            }
        }

        return $this->decoder->decode($client, $candidates, $watched, $from, $to);
    }

    private function query(ChainClient $client, int $from, int $to, array $addresses, string $direction): array
    {
        try {
            return $client->logs($from, $to, $addresses, $direction);
        } catch (RangeLimitException $exception) {
            if ($exception instanceof QueryPayloadLimitException && count($addresses) > 1) {
                $middle = intdiv(count($addresses), 2);

                return array_merge($this->query($client, $from, $to, array_slice($addresses, 0, $middle), $direction), $this->query($client, $from, $to, array_slice($addresses, $middle), $direction));
            }
            if ($from < $to) {
                $middle = $from + intdiv($to - $from, 2);

                return array_merge($this->query($client, $from, $middle, $addresses, $direction), $this->query($client, $middle + 1, $to, $addresses, $direction));
            }
            if (count($addresses) > 1) {
                $middle = intdiv(count($addresses), 2);

                return array_merge($this->query($client, $from, $to, array_slice($addresses, 0, $middle), $direction), $this->query($client, $from, $to, array_slice($addresses, $middle), $direction));
            }
            if ($client->chain() !== 'tron' || ! method_exists($client, 'solidifiedBlockReceipts')) {
                throw new ScanException('A single-block log result exceeds provider limits; coverage remains unfinished.');
            }
            $logs = [];
            foreach ($client->solidifiedBlockReceipts($from) as $receipt) {
                if (Hex::integer($receipt['status'] ?? null) !== 1) {
                    continue;
                }
                foreach ($receipt['logs'] ?? [] as $log) {
                    if (! is_array($log) || strtolower($log['address'] ?? '') !== $this->decoder->contract('tron')
                        || ($log['topics'][0] ?? null) !== EthereumRpcClient::TRANSFER_TOPIC) {
                        continue;
                    }
                    $position = $direction === 'outgoing' ? 1 : 2;
                    if (in_array($log['topics'][$position] ?? null, array_map(fn (string $address): string => AddressCodec::topic('tron', $address), $addresses), true)) {
                        $logs[] = $log + [
                            'transactionHash' => $receipt['transactionHash'], 'blockHash' => $receipt['blockHash'], 'blockNumber' => $receipt['blockNumber'],
                        ];
                    }
                }
            }

            return $logs;
        }
    }

    private function watchSet(string $chain, int $watchSetId): object
    {
        $set = DB::table('watch_sets')->where('id', $watchSetId)->where('chain', $chain)->first();
        if ($set === null) {
            throw new ScanException('Watch set does not exist for this chain.');
        }

        return $set;
    }

    private function validateHeader(array $block): void
    {
        if (! is_int($block['number'] ?? null) || $block['number'] < 0 || ! is_int($block['timestamp'] ?? null) || $block['timestamp'] < 0) {
            throw new ScanException('Canonical block header is incomplete.');
        }
        Hex::data($block['hash'] ?? null, 32);
    }

    private function pauseBoundaryMismatch(object $cursor): void
    {
        DB::table('scan_cursors')->where('id', $cursor->id)->where('last_block', $cursor->last_block)
            ->where('last_block_hash', $cursor->last_block_hash)->update([
                'paused_at' => now(), 'pause_reason' => 'Finalized boundary hash mismatch.', 'updated_at' => now(),
            ]);
    }
}
