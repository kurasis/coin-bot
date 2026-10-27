<?php

namespace App\Reconciliation;

use App\Blockchain\Contracts\ChainClient;
use App\Monitoring\ScanException;

/** Shared only by a bounded batch; event and boundary evidence is never cached here. */
final class FinalizedHeadSnapshot implements ChainClient
{
    private bool $verified = false;

    private ?array $head = null;

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
        if (! $this->verified) {
            $this->inner->verifyNetwork();
            $this->verified = true;
        }
    }

    public function finalizedBlock(): array
    {
        return $this->head ??= $this->inner->finalizedBlock();
    }

    public function block(int $number): array
    {
        return $this->inner->block($number);
    }

    public function logs(int $from, int $to, array $normalizedWatchedAddresses, string $direction): array
    {
        return $this->inner->logs($from, $to, $normalizedWatchedAddresses, $direction);
    }

    public function receipt(string $transactionHash): array
    {
        return $this->inner->receipt($transactionHash);
    }

    public function solidifiedBlockReceipts(int $number): array
    {
        if (! is_callable([$this->inner, 'solidifiedBlockReceipts'])) {
            throw new ScanException('Native solidified block receipt fallback is unavailable.');
        }

        return $this->inner->solidifiedBlockReceipts($number);
    }
}
