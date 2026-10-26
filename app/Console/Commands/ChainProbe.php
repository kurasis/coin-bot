<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Blockchain\AddressCodec;
use App\Blockchain\ChainClientFactory;
use App\Blockchain\Exceptions\RpcException;
use App\Blockchain\Hex;
use Illuminate\Console\Command;

class ChainProbe extends Command
{
    protected $signature = 'chain:probe {chain : ethereum or tron} {--provider=primary : primary or secondary} {--address=* : Watched address; repeat for OR-filter checks} {--from= : Inclusive decimal block height} {--to= : Inclusive decimal block height}';

    protected $description = 'Read-only network, finality, filtered-log, and receipt capability probe';

    public function handle(ChainClientFactory $factory): int
    {
        try {
            $chain = (string) $this->argument('chain');
            $addresses = $this->option('address');
            if ($addresses === []) {
                $this->error('Provide at least one --address; unfiltered contract scans are not allowed.');

                return self::INVALID;
            }
            $normalized = array_values(array_unique(array_map(static fn (string $address): string => AddressCodec::normalize($chain, $address), $addresses)));
            $client = $factory->make($chain, (string) $this->option('provider'));
            $client->verifyNetwork();
            $head = $client->finalizedBlock();
            $from = $this->height($this->option('from'), $head['number']);
            $to = $this->height($this->option('to'), $head['number']);
            if ($from > $to || $to > $head['number']) {
                throw new RpcException('Requested range must be ordered and must not exceed the finalized height.');
            }
            $incoming = $client->logs($from, $to, $normalized, 'incoming');
            $outgoing = $client->logs($from, $to, $normalized, 'outgoing');
            $discovered = array_merge($incoming, $outgoing);
            $hashes = array_values(array_unique(array_column($discovered, 'transactionHash')));
            // A capability probe samples full receipts; the scanner verifies every matching transaction.
            $sample = array_slice($hashes, 0, 5);
            foreach ($sample as $hash) {
                $receipt = $client->receipt($hash);
                $number = Hex::integer($receipt['blockNumber']);
                if ($number < $from || $number > $to || Hex::integer($receipt['status']) !== 1 || $client->block($number)['hash'] !== $receipt['blockHash']) {
                    throw new RpcException('A sampled receipt failed canonical execution verification.');
                }
                foreach ($discovered as $log) {
                    if ($log['transactionHash'] === $hash && ! array_any($receipt['logs'], static fn (array $item): bool => $item['address'] === $log['address'] && $item['topics'] === $log['topics'] && $item['data'] === $log['data'])) {
                        throw new RpcException('A sampled receipt does not contain a discovered transfer.');
                    }
                }
            }
            $this->line(json_encode([
                'status' => 'ok', 'chain' => $client->chain(), 'provider' => $client->provider(),
                'finalized_height' => $head['number'], 'from_block' => $from, 'to_block' => $to,
                'watched_addresses' => count($normalized), 'incoming_matches' => count($incoming),
                'outgoing_matches' => count($outgoing), 'receipts_checked' => count($sample),
                'receipt_sample_limited' => count($hashes) > count($sample),
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (RpcException $error) {
            $this->error('Probe failed: '.$error->getMessage());

            return self::FAILURE;
        }
    }

    private function height(mixed $value, int $default): int
    {
        if ($value === null) {
            return $default;
        }
        if (! is_string($value) || ! preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new RpcException('Block heights must be non-negative decimal integers.');
        }

        return (int) $value;
    }
}
