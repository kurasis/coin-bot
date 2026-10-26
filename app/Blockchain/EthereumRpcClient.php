<?php

declare(strict_types=1);

namespace App\Blockchain;

use App\Blockchain\Contracts\ChainClient;
use App\Blockchain\Exceptions\RangeLimitException;
use App\Blockchain\Exceptions\RpcException;

class EthereumRpcClient implements ChainClient
{
    public const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    public const USDT_CONTRACT = '0xdac17f958d2ee523a2206206994597c13d831ec7';

    public function __construct(
        protected readonly RpcTransport $transport,
        protected readonly string $providerName = 'primary',
        protected readonly array $limits = [],
    ) {}

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
        if (Hex::integer($this->transport->rpc('eth_chainId', [])) !== 1) {
            throw new RpcException('Blockchain endpoint is not Ethereum mainnet.');
        }
    }

    public function finalizedBlock(): array
    {
        return $this->parseBlock($this->transport->rpc('eth_getBlockByNumber', ['finalized', false]));
    }

    public function block(int $number): array
    {
        $block = $this->parseBlock($this->transport->rpc('eth_getBlockByNumber', [Hex::quantity($number), false]));
        if ($block['number'] !== $number) {
            throw new RpcException('Blockchain endpoint returned the wrong block.');
        }

        return $block;
    }

    public function logs(int $from, int $to, array $normalizedWatchedAddresses, string $direction): array
    {
        if ($from < 0 || $to < $from || ! in_array($direction, ['incoming', 'outgoing'], true)) {
            throw new RpcException('Invalid blockchain scan parameters.');
        }
        if ($normalizedWatchedAddresses === []) {
            return [];
        }
        if ($to - $from + 1 > max(1, (int) ($this->limits['max_block_range'] ?? 5000)) || count($normalizedWatchedAddresses) > max(1, (int) ($this->limits['max_topic_addresses'] ?? 100))) {
            throw new RangeLimitException;
        }
        $watchedTopics = array_values(array_unique(array_map(fn (string $address): string => AddressCodec::topic($this->chain(), $address), $normalizedWatchedAddresses)));
        $topics = $direction === 'outgoing' ? [self::TRANSFER_TOPIC, $watchedTopics] : [self::TRANSFER_TOPIC, null, $watchedTopics];
        $result = $this->transport->rpc('eth_getLogs', [[
            'address' => $this->contract(), 'fromBlock' => Hex::quantity($from), 'toBlock' => Hex::quantity($to), 'topics' => $topics,
        ]]);
        if (! is_array($result) || ! array_is_list($result)) {
            throw new RpcException('Blockchain log response is not a list.');
        }
        if (count($result) >= max(1, (int) ($this->limits['max_results'] ?? 1000))) {
            throw new RangeLimitException;
        }
        $logs = [];
        foreach ($result as $raw) {
            $log = $this->validateLog($raw);
            $number = Hex::integer($log['blockNumber'] ?? null);
            $topicPosition = $direction === 'outgoing' ? 1 : 2;
            if ($number < $from || $number > $to || $log['address'] !== $this->contract() || ($log['topics'][0] ?? null) !== self::TRANSFER_TOPIC || ! in_array($log['topics'][$topicPosition] ?? null, $watchedTopics, true)) {
                throw new RpcException('Blockchain endpoint returned logs outside the requested filter.');
            }
            $log['blockHash'] = Hex::data($log['blockHash'] ?? null, 32);
            $log['transactionHash'] = Hex::data($log['transactionHash'] ?? null, 32);
            Hex::integer($log['logIndex'] ?? null);
            $logs[] = $log;
        }

        return $logs;
    }

    public function receipt(string $transactionHash): array
    {
        $transactionHash = Hex::data($transactionHash, 32);
        $receipt = $this->transport->rpc('eth_getTransactionReceipt', [$transactionHash]);
        if (! is_array($receipt) || ($receipt['transactionHash'] ?? null) === null) {
            throw new RpcException('Transaction receipt is not available.', true);
        }
        $receipt['transactionHash'] = Hex::data($receipt['transactionHash'], 32);
        $receipt['blockHash'] = Hex::data($receipt['blockHash'] ?? null, 32);
        $number = Hex::integer($receipt['blockNumber'] ?? null);
        $status = Hex::integer($receipt['status'] ?? null);
        if ($receipt['transactionHash'] !== $transactionHash || ! in_array($status, [0, 1], true) || ! isset($receipt['logs']) || ! is_array($receipt['logs']) || ! array_is_list($receipt['logs'])) {
            throw new RpcException('Invalid transaction receipt.');
        }
        $logs = [];
        foreach ($receipt['logs'] as $raw) {
            $log = $this->validateLog($raw);
            if (isset($log['blockNumber']) && Hex::integer($log['blockNumber']) !== $number) {
                throw new RpcException('Transaction receipt contains an inconsistent block number.');
            }
            if (isset($log['blockHash']) && Hex::data($log['blockHash'], 32) !== $receipt['blockHash']) {
                throw new RpcException('Transaction receipt contains an inconsistent block hash.');
            }
            if (isset($log['transactionHash']) && Hex::data($log['transactionHash'], 32) !== $transactionHash) {
                throw new RpcException('Transaction receipt contains an inconsistent transaction hash.');
            }
            $logs[] = $log;
        }
        $receipt['logs'] = $logs;

        return $receipt;
    }

    protected function contract(): string
    {
        return self::USDT_CONTRACT;
    }

    protected function parseBlock(mixed $raw): array
    {
        if (! is_array($raw)) {
            throw new RpcException('Block is not available.', true);
        }

        return ['number' => Hex::integer($raw['number'] ?? null), 'hash' => Hex::data($raw['hash'] ?? null, 32), 'timestamp' => Hex::integer($raw['timestamp'] ?? null)];
    }

    protected function validateLog(mixed $raw): array
    {
        if (! is_array($raw) || ! isset($raw['topics']) || ! is_array($raw['topics']) || ! array_is_list($raw['topics']) || count($raw['topics']) > 4) {
            throw new RpcException('Invalid blockchain log.');
        }
        if (($raw['removed'] ?? false) !== false) {
            throw new RpcException('Blockchain log is no longer canonical.', true);
        }
        $raw['address'] = Hex::data($raw['address'] ?? null, 20);
        $raw['data'] = Hex::data($raw['data'] ?? null);
        $raw['topics'] = array_map(static fn (mixed $topic): string => Hex::data($topic, 32), $raw['topics']);

        return $raw;
    }
}
