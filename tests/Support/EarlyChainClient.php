<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Blockchain\AddressCodec;
use App\Blockchain\Contracts\ChainClient;
use App\Blockchain\EthereumRpcClient;
use App\Blockchain\Exceptions\RpcException;
use App\Blockchain\Hex;

/** Synthetic canonical history. Only the external chain boundary is simulated. */
final class EarlyChainClient implements ChainClient
{
    public int $latestHead = 15;

    public int $finalizedHead = 9;

    public array $requests = [];

    public bool $receiptFailure = false;

    public mixed $duringReceipt = null;

    public mixed $duringBlock = null;

    private array $events = [];

    private int $forkFrom = PHP_INT_MAX;

    private int $forkVersion = 0;

    public function __construct(public string $providerName = 'primary') {}

    public function chain(): string
    {
        return 'ethereum';
    }

    public function provider(): string
    {
        return $this->providerName;
    }

    public function verifyNetwork(): void
    {
        $this->requests[] = 'network';
    }

    public function latestBlock(): array
    {
        $this->requests[] = 'latest';

        return $this->block($this->latestHead);
    }

    public function finalizedBlock(): array
    {
        $this->requests[] = 'finalized';

        return $this->block($this->finalizedHead);
    }

    public function block(int $number): array
    {
        $this->requests[] = 'block:'.$number;

        $header = [
            'number' => $number,
            'hash' => '0x'.hash('sha256', 'early:block:'.$number.':'.($number >= $this->forkFrom ? $this->forkVersion : 0)),
            'timestamp' => 1793030400 + $number * 12,
        ];
        if ($this->duringBlock !== null) {
            ($this->duringBlock)($number);
        }

        return $header;
    }

    public function addEvent(int $height, string $sender, string $recipient, int $amount = 1000000, string $seed = 'event'): string
    {
        $hash = '0x'.hash('sha256', 'early:transaction:'.$seed);
        $this->events[$hash] = compact('height', 'sender', 'recipient', 'amount');

        return $hash;
    }

    public function reorganize(int $from, bool $removeEvents = true): void
    {
        $this->forkFrom = $from;
        $this->forkVersion++;
        if ($removeEvents) {
            $this->events = array_filter($this->events, fn (array $event): bool => $event['height'] < $from);
        }
    }

    public function logs(int $from, int $to, array $normalizedWatchedAddresses, string $direction): array
    {
        $this->requests[] = 'logs:'.$from.':'.$to.':'.$direction;
        $matches = [];
        foreach ($this->events as $hash => $event) {
            if ($event['height'] < $from || $event['height'] > $to) {
                continue;
            }
            $address = $direction === 'incoming' ? $event['recipient'] : $event['sender'];
            if (in_array($address, $normalizedWatchedAddresses, true)) {
                $matches[] = $this->eventLog($hash, $event);
            }
        }

        return $matches;
    }

    public function receipt(string $transactionHash): array
    {
        $this->requests[] = 'receipt:'.$transactionHash;
        if ($this->receiptFailure || ! isset($this->events[$transactionHash])) {
            throw new RpcException('Synthetic receipt unavailable.', true);
        }
        $event = $this->events[$transactionHash];
        $receipt = [
            'transactionHash' => $transactionHash,
            'blockNumber' => Hex::quantity($event['height']),
            'blockHash' => $this->block($event['height'])['hash'],
            'status' => '0x1',
            'logs' => [
                ['address' => self::address(999), 'topics' => [], 'data' => '0x'],
                $this->eventLog($transactionHash, $event),
            ],
        ];
        if ($this->duringReceipt !== null) {
            $callback = $this->duringReceipt;
            $this->duringReceipt = null;
            $callback();
        }

        return $receipt;
    }

    private function eventLog(string $hash, array $event): array
    {
        return [
            'address' => EthereumRpcClient::USDT_CONTRACT,
            'transactionHash' => $hash,
            'blockNumber' => Hex::quantity($event['height']),
            'blockHash' => $this->block($event['height'])['hash'],
            'logIndex' => '0x1',
            'removed' => false,
            'topics' => [EthereumRpcClient::TRANSFER_TOPIC,
                AddressCodec::topic('ethereum', $event['sender']), AddressCodec::topic('ethereum', $event['recipient'])],
            'data' => '0x'.str_pad(dechex($event['amount']), 64, '0', STR_PAD_LEFT),
        ];
    }

    public static function address(int $number): string
    {
        return '0x'.str_pad(dechex($number), 40, '0', STR_PAD_LEFT);
    }
}
