<?php

declare(strict_types=1);

namespace App\Blockchain;

use App\Blockchain\Exceptions\RpcException;

final class AddressCodec
{
    private const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public static function normalize(string $chain, string $address): string
    {
        if ($chain === 'ethereum') {
            return Hex::data($address, 20);
        }
        if ($chain !== 'tron') {
            throw new RpcException('Unsupported blockchain.');
        }

        if (preg_match('/^(?:0x)?41[0-9a-f]{40}$/iD', $address)) {
            $payload = hex2bin(preg_replace('/^0x/i', '', $address));
        } elseif (preg_match('/^0x[0-9a-f]{40}$/iD', $address)) {
            $payload = hex2bin('41'.substr($address, 2));
        } else {
            $decoded = self::decodeBase58($address);
            if (strlen($decoded) !== 25) {
                throw new RpcException('Invalid TRON address.');
            }
            $payload = substr($decoded, 0, 21);
            if (! hash_equals(self::checksum($payload), substr($decoded, 21))) {
                throw new RpcException('Invalid TRON address checksum.');
            }
        }

        if (strlen($payload) !== 21 || ord($payload[0]) !== 0x41) {
            throw new RpcException('Invalid TRON mainnet address.');
        }

        return self::encodeBase58($payload.self::checksum($payload));
    }

    public static function toEvm(string $chain, string $address): string
    {
        $normalized = self::normalize($chain, $address);
        if ($chain === 'ethereum') {
            return $normalized;
        }

        return '0x'.bin2hex(substr(self::decodeBase58($normalized), 1, 20));
    }

    public static function topic(string $chain, string $address): string
    {
        return '0x'.str_pad(substr(self::toEvm($chain, $address), 2), 64, '0', STR_PAD_LEFT);
    }

    private static function checksum(string $payload): string
    {
        return substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
    }

    private static function decodeBase58(string $input): string
    {
        if ($input === '' || strlen($input) > 40) {
            throw new RpcException('Invalid TRON address.');
        }
        $bytes = [0];
        foreach (str_split($input) as $character) {
            $digit = strpos(self::ALPHABET, $character);
            if ($digit === false) {
                throw new RpcException('Invalid TRON address.');
            }
            $carry = $digit;
            foreach ($bytes as &$byte) {
                $carry += $byte * 58;
                $byte = $carry & 0xFF;
                $carry >>= 8;
            }
            unset($byte);
            while ($carry > 0) {
                $bytes[] = $carry & 0xFF;
                $carry >>= 8;
            }
        }

        $result = implode('', array_map(chr(...), array_reverse($bytes)));
        $leadingZeros = strspn($input, '1');

        return str_repeat("\0", $leadingZeros).($result === "\0" ? '' : $result);
    }

    private static function encodeBase58(string $input): string
    {
        $digits = [0];
        foreach (unpack('C*', $input) as $byte) {
            $carry = $byte;
            foreach ($digits as &$digit) {
                $carry += $digit * 256;
                $digit = $carry % 58;
                $carry = intdiv($carry, 58);
            }
            unset($digit);
            while ($carry > 0) {
                $digits[] = $carry % 58;
                $carry = intdiv($carry, 58);
            }
        }

        return str_repeat('1', strspn($input, "\0")).implode('', array_map(static fn (int $digit): string => self::ALPHABET[$digit], array_reverse($digits)));
    }
}
