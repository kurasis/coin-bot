<?php

namespace App\Wallets;

use InvalidArgumentException;

final class WalletInput
{
    public static function integer(mixed $value, bool $positive = false): int
    {
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^[0-9]+$/D', (string) $value)) {
            throw new InvalidArgumentException('Expected an unsigned integer.');
        }
        $normalized = ltrim((string) $value, '0') ?: '0';
        if (strlen($normalized) > strlen((string) PHP_INT_MAX)
            || (strlen($normalized) === strlen((string) PHP_INT_MAX) && strcmp($normalized, (string) PHP_INT_MAX) > 0)
            || ($positive && $normalized === '0')) {
            throw new InvalidArgumentException('Integer is outside the supported range.');
        }

        return (int) $normalized;
    }
}
