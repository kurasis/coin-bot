<?php

declare(strict_types=1);

namespace App\Reconciliation;

use App\Blockchain\Contracts\ChainClient;
use App\Monitoring\ScanException;

/** A per-operation witness cache; it never survives a reconciliation attempt. */
final class CachedChainClient implements ChainClient
{
    private array $receipts = [];

    private array $blocks = [];

    public function __construct(private readonly ChainClient $inner) {}

    public function chain(): string
    {
        return $this->inner->chain();
    }

    public function provider(): string
    {
        return $this->inner->provider();
    }

    public function verifyNetwork(): void
    {
        $this->inner->verifyNetwork();
    }

    public function finalizedBlock(): array
    {
        return $this->inner->finalizedBlock();
    }

    public function block(int $number): array
    {
        return $this->blocks[$number] ??= $this->inner->block($number);
    }

    public function logs(int $from, int $to, array $normalizedWatchedAddresses, string $direction): array
    {
        return $this->inner->logs($from, $to, $normalizedWatchedAddresses, $direction);
    }

    /** Forward native TRON receipt coverage without erasing the underlying client's capability. */
    public function solidifiedBlockReceipts(int $number): array
    {
        if (! method_exists($this->inner, 'solidifiedBlockReceipts')) {
            throw new ScanException('Native solidified block receipt fallback is unavailable.');
        }

        return $this->inner->solidifiedBlockReceipts($number);
    }

    public function receipt(string $transactionHash): array
    {
        return $this->receipts[$transactionHash] ??= $this->inner->receipt($transactionHash);
    }
}
