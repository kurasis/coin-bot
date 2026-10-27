<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Blockchain\Exceptions\RpcException;

/** Sanitized witness attribution without retaining client credentials or a previous exception. */
final class EarlyWitnessException extends ScanException
{
    public readonly bool $retryable;

    public readonly ?int $httpStatus;

    public readonly ?int $retryAfterSeconds;

    public function __construct(public readonly string $provider, #[\SensitiveParameter] RpcException $error)
    {
        $this->retryable = $error->retryable;
        $this->httpStatus = $error->httpStatus;
        $this->retryAfterSeconds = $error->retryAfterSeconds;
        parent::__construct('Independent Ethereum reorganization witness is unavailable.');
    }
}
