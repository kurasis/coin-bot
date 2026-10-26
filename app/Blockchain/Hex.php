<?php

declare(strict_types=1);

namespace App\Blockchain;

use App\Blockchain\Exceptions\RpcException;

final class Hex
{
    public static function quantity(int $value): string
    {
        if ($value < 0) {
            throw new RpcException('A blockchain quantity cannot be negative.');
        }

        return '0x'.dechex($value);
    }

    public static function integer(mixed $value): int
    {
        if (! is_string($value) || ! preg_match('/^0x(?:0|[1-9a-f][0-9a-f]*)$/iD', $value)) {
            throw new RpcException('Blockchain response contains an invalid quantity.');
        }

        $digits = strtolower(substr($value, 2));
        $maximum = dechex(PHP_INT_MAX);
        if (strlen($digits) > strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            throw new RpcException('Blockchain quantity exceeds the supported integer range.');
        }

        return intval($digits, 16);
    }

    public static function data(mixed $value, ?int $bytes = null): string
    {
        if (! is_string($value) || ! preg_match('/^0x(?:[0-9a-f]{2})*$/iD', $value) || ($bytes !== null && strlen($value) !== 2 + $bytes * 2)) {
            throw new RpcException('Blockchain response contains invalid hexadecimal data.');
        }

        return strtolower($value);
    }
}
