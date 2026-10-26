<?php

declare(strict_types=1);

namespace App\Blockchain\Exceptions;

use RuntimeException;

class RpcException extends RuntimeException
{
    public function __construct(
        string $message = 'Blockchain request failed.',
        public readonly bool $retryable = false,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        // Never attach upstream exception messages: endpoint URLs may contain API keys.
        parent::__construct($message);
    }
}
