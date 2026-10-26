<?php

namespace App\Domain;

use InvalidArgumentException;

/** Exact uint256 arithmetic for token amounts, independent of PHP's integer size. */
final readonly class Amount
{
    public const MAX_UINT256 = '115792089237316195423570985008687907853269984665640564039457584007913129639935';

    private string $value;

    public function __construct(string $value)
    {
        if (! preg_match('/^[0-9]+$/D', $value)) {
            throw new InvalidArgumentException('An amount must be an unsigned decimal integer.');
        }

        $normalized = ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;

        if (strlen($normalized) > 78 || (strlen($normalized) === 78 && strcmp($normalized, self::MAX_UINT256) > 0)) {
            throw new InvalidArgumentException('Amount exceeds uint256.');
        }

        $this->value = $normalized;
    }

    public static function fromHex(string $hex): self
    {
        if (! preg_match('/^0x[0-9a-fA-F]{1,64}$/D', $hex)) {
            throw new InvalidArgumentException('An amount must be a hexadecimal uint256.');
        }

        $decimal = '0';
        foreach (str_split(substr($hex, 2)) as $digit) {
            $decimal = self::multiplyAndAdd($decimal, 16, hexdec($digit));
        }

        return new self($decimal);
    }

    public function raw(): string
    {
        return $this->value;
    }

    public function format(int $decimals = 6): string
    {
        if ($decimals < 0 || $decimals > 255) {
            throw new InvalidArgumentException('Decimals must be between zero and 255.');
        }

        if ($decimals === 0) {
            return $this->value;
        }

        $padded = str_pad($this->value, $decimals + 1, '0', STR_PAD_LEFT);

        return substr($padded, 0, -$decimals).'.'.substr($padded, -$decimals);
    }

    public function compareTo(self $other): int
    {
        $length = strlen($this->value) <=> strlen($other->value);

        return $length !== 0 ? $length : (strcmp($this->value, $other->value) <=> 0);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function multiplyAndAdd(string $value, int $multiplier, int $carry): string
    {
        $result = '';
        for ($index = strlen($value) - 1; $index >= 0; $index--) {
            $product = ((int) $value[$index]) * $multiplier + $carry;
            $result = (string) ($product % 10).$result;
            $carry = intdiv($product, 10);
        }
        while ($carry > 0) {
            $result = (string) ($carry % 10).$result;
            $carry = intdiv($carry, 10);
        }

        return ltrim($result, '0') ?: '0';
    }
}
