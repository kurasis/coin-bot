<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Blockchain\Contracts\ChainClient;
use App\Blockchain\Exceptions\RpcException;

/** Track provider operations, not systemd restarts or circuit-only skips. */
final class CheckedChainClient implements ChainClient
{
    public function __construct(private readonly ChainClient $client, private readonly CheckAttempt $attempt) {}

    public function chain(): string
    {
        return $this->client->chain();
    }

    public function provider(): string
    {
        return $this->client->provider();
    }

    public function verifyNetwork(): void
    {
        $this->attempt->mark();
        $this->client->verifyNetwork();
    }

    public function finalizedBlock(): array
    {
        $this->attempt->mark();

        return $this->client->finalizedBlock();
    }

    public function latestBlock(): array
    {
        if (! is_callable([$this->client, 'latestBlock'])) {
            throw new RpcException('The Ethereum client does not support a latest-block check.');
        }
        $this->attempt->mark();

        return $this->client->latestBlock();
    }

    public function block(int $number): array
    {
        $this->attempt->mark();

        return $this->client->block($number);
    }

    public function logs(int $from, int $to, array $normalizedWatchedAddresses, string $direction): array
    {
        $this->attempt->mark();

        return $this->client->logs($from, $to, $normalizedWatchedAddresses, $direction);
    }

    public function receipt(string $transactionHash): array
    {
        $this->attempt->mark();

        return $this->client->receipt($transactionHash);
    }

    public function solidifiedBlockReceipts(int $number): array
    {
        if (! is_callable([$this->client, 'solidifiedBlockReceipts'])) {
            throw new RpcException('The TRON client does not support native block receipts.');
        }
        $this->attempt->mark();

        return $this->client->solidifiedBlockReceipts($number);
    }
}
