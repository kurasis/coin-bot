<?php

declare(strict_types=1);

namespace App\Reconciliation;

final readonly class ReconciliationResult
{
    public function __construct(
        public ?int $rangeId = null,
        public int $repairedEvents = 0,
        public int $indexOmissions = 0,
        public bool $idle = false,
        public bool $waitingForFinality = false,
        public bool $waitingForHistory = false,
    ) {}
}
