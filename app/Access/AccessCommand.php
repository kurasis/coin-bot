<?php

namespace App\Access;

use Illuminate\Console\Command;
use InvalidArgumentException;

abstract class AccessCommand extends Command
{
    protected function positiveInteger(mixed $value, string $label): int
    {
        if (! is_string($value) || ! preg_match('/^[1-9][0-9]{0,8}$/D', $value)) {
            throw new InvalidArgumentException($label.' must be a positive integer.');
        }

        return (int) $value;
    }

    protected function localOperator(): string
    {
        return (string) $this->option('operator');
    }
}
