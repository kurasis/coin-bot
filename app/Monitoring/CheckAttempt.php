<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Operations\SanitizedFailure;
use Throwable;

final class CheckAttempt
{
    public bool $attempted = false;

    public int $calls = 0;

    public array $failures = [];

    public function failed(string $provider, string $operation, Throwable $error): void
    {
        // Retain only the latest failure per provider; recursive range splitting
        // must not build an unbounded diagnostic history.
        $this->failures[$provider] = ['operation' => $operation] + SanitizedFailure::details($error);
    }

    public function mark(): void
    {
        $this->attempted = true;
        $this->calls++;
    }
}
