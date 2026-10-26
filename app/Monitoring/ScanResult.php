<?php

declare(strict_types=1);

namespace App\Monitoring;

final readonly class ScanResult
{
    public function __construct(
        public int $cursorId,
        public ?int $fromBlock,
        public ?int $toBlock,
        public int $events,
        public bool $idle = false,
    ) {}
}
