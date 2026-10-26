<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Blockchain\AddressCodec;
use App\Blockchain\Contracts\ChainClient;
use App\Blockchain\EthereumRpcClient;
use App\Blockchain\Exceptions\RpcException;
use App\Blockchain\Hex;
use App\Blockchain\TronRpcClient;

/** Deterministic finalized history; only the external blockchain boundary is simulated. */
final class ReleaseChainClient implements ChainClient
{
    public array $requests = [];

    public ?int $failOnceAtFrom = null;

    private array $receipts = [];

    public function __construct(private readonly string $network, public int $head, array $events)
    {
        foreach ($events as $event) {
            $height = $event['height'];
            $hash = '0x'.hash('sha256', $network.':transaction:'.$height);
            $block = $this->block($height);
            $unrelated = ['address' => self::hexAddress(999), 'topics' => [], 'data' => '0x'];
            $logs = [$unrelated];
            for ($copy = 1; $copy <= ($event['copies'] ?? 1); $copy++) {
                $logs[] = [
                    'address' => $network === 'ethereum' ? EthereumRpcClient::USDT_CONTRACT : TronRpcClient::USDT_CONTRACT,
                    'transactionHash' => $hash, 'blockHash' => $block['hash'], 'blockNumber' => Hex::quantity($height),
                    'logIndex' => Hex::quantity($copy), 'removed' => false,
                    'topics' => [EthereumRpcClient::TRANSFER_TOPIC, AddressCodec::topic($network, $event['from']), AddressCodec::topic($network, $event['to'])],
                    'data' => '0x'.str_pad(dechex($event['amount']), 64, '0', STR_PAD_LEFT),
                ];
            }
            $this->receipts[$hash] = [
                'transactionHash' => $hash, 'blockNumber' => Hex::quantity($height), 'blockHash' => $block['hash'],
                'status' => '0x1', 'logs' => $logs,
            ];
        }
    }

    public function chain(): string
    {
        return $this->network;
    }

    public function provider(): string
    {
        return 'primary';
    }

    public function verifyNetwork(): void {}

    public function finalizedBlock(): array
    {
        return $this->block($this->head);
    }

    public function block(int $number): array
    {
        return ['number' => $number, 'hash' => '0x'.hash('sha256', $this->network.':block:'.$number), 'timestamp' => 1700000000 + $number * ($this->network === 'tron' ? 3 : 12)];
    }

    public function logs(int $from, int $to, array $normalizedWatchedAddresses, string $direction): array
    {
        $this->requests[] = compact('from', 'to', 'direction');
        if ($this->failOnceAtFrom === $from && $direction === 'incoming') {
            $this->failOnceAtFrom = null;
            throw new RpcException('Simulated temporary provider outage.', true);
        }
        $topics = array_map(fn (string $address): string => AddressCodec::topic($this->network, $address), $normalizedWatchedAddresses);
        $result = [];
        foreach ($this->receipts as $receipt) {
            $height = Hex::integer($receipt['blockNumber']);
            if ($height < $from || $height > $to) {
                continue;
            }
            foreach ($receipt['logs'] as $log) {
                if (($log['topics'][0] ?? null) === EthereumRpcClient::TRANSFER_TOPIC
                    && in_array($log['topics'][$direction === 'outgoing' ? 1 : 2], $topics, true)) {
                    $result[] = $log;
                }
            }
        }

        return $result;
    }

    public function receipt(string $transactionHash): array
    {
        return $this->receipts[$transactionHash] ?? throw new RpcException('Simulated receipt unavailable.', true);
    }

    public static function hexAddress(int $number): string
    {
        return '0x'.str_pad(dechex($number), 40, '0', STR_PAD_LEFT);
    }
}
