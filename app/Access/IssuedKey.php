<?php

namespace App\Access;

use Carbon\CarbonImmutable;

final readonly class IssuedKey
{
    public function __construct(
        public int $id,
        public string $token,
        public CarbonImmutable $expiresAt,
        public ?CarbonImmutable $grantExpiresAt,
    ) {}
}
