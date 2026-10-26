<?php

declare(strict_types=1);

namespace App\Reconciliation;

use RuntimeException;

final class ProviderCoolingDown extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Provider circuit is cooling down; no network request was made.');
    }
}
