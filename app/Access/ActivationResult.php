<?php

namespace App\Access;

final readonly class ActivationResult
{
    public function __construct(
        public int $userId,
        public int $grantId,
        public bool $replayed = false,
    ) {}
}
