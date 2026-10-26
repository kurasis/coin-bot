<?php

declare(strict_types=1);

namespace App\Blockchain\Contracts;

interface ChainClient
{
    public function chain(): string;

    public function provider(): string;

    public function verifyNetwork(): void;

    /** @return array{number: int, hash: string, timestamp: int} Timestamp is UNIX seconds. */
    public function finalizedBlock(): array;

    /** @return array{number: int, hash: string, timestamp: int} Timestamp is UNIX seconds. */
    public function block(int $number): array;

    /** @return list<array<string, mixed>> */
    public function logs(int $from, int $to, array $normalizedWatchedAddresses, string $direction): array;

    /** @return array<string, mixed> EVM-shaped receipt with its complete, ordered log array. */
    public function receipt(string $transactionHash): array;
}
