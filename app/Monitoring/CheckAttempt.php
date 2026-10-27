<?php

declare(strict_types=1);

namespace App\Monitoring;

final class CheckAttempt
{
    public bool $attempted = false;

    public int $calls = 0;

    public function mark(): void
    {
        $this->attempted = true;
        $this->calls++;
    }
}
