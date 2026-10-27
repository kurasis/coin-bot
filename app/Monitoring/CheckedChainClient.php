<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Blockchain\Contracts\ChainClient;
use App\Blockchain\Exceptions\RpcException;
use Throwable;

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
        $this->call('network', fn () => $this->client->verifyNetwork());
    }

    public function finalizedBlock(): array
    {
        return $this->call('finalized', fn () => $this->client->finalizedBlock());
    }

    public function latestBlock(): array
    {
        if (! is_callable([$this->client, 'latestBlock'])) {
            throw new RpcException('The Ethereum client does not support a latest-block check.');
        }

        return $this->call('latest', fn () => $this->client->latestBlock());
    }

    public function block(int $number): array
    {
        return $this->call('block', fn () => $this->client->block($number));
    }

    public function logs(int $from, int $to, array $normalizedWatchedAddresses, string $direction): array
    {
        return $this->call('logs_'.$direction, fn () => $this->client->logs($from, $to, $normalizedWatchedAddresses, $direction));
    }

    public function receipt(string $transactionHash): array
    {
        return $this->call('receipt', fn () => $this->client->receipt($transactionHash));
    }

    public function solidifiedBlockReceipts(int $number): array
    {
        if (! is_callable([$this->client, 'solidifiedBlockReceipts'])) {
            throw new RpcException('The TRON client does not support native block receipts.');
        }

        return $this->call('solidified_receipts', fn () => $this->client->solidifiedBlockReceipts($number));
    }

    private function call(string $operation, callable $request): mixed
    {
        $this->attempt->mark();
        try {
            $result = $request();
            // A limit that was resolved by splitting is no longer a failure.
            unset($this->attempt->failures[$this->provider()]);

            return $result;
        } catch (Throwable $error) {
            $this->attempt->failed($this->provider(), $operation, $error);
            throw $error;
        }
    }
}
