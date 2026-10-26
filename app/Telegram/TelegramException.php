<?php

namespace App\Telegram;

use RuntimeException;

final class TelegramException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly ?int $retryAfter = null,
        public readonly bool $ambiguous = false,
    ) {
        // Never retain upstream response descriptions or exceptions containing the bot URL.
        parent::__construct('Telegram request failed (code '.$status.').');
    }

    public function retryable(): bool
    {
        return $this->status === 0 || $this->status === 429 || $this->status >= 500;
    }
}
