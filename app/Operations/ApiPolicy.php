<?php

namespace App\Operations;

use InvalidArgumentException;

final readonly class ApiPolicy
{
    public function __construct(public string $group, public string $profile, public int $rate, public int $dailyTarget, public int $quicknodeCost = 20) {}

    public static function from(#[\SensitiveParameter] array $settings, string $chain, string $provider): self
    {
        $profile = $settings['usage_profile'] ?? 'auto';
        if ($profile === 'auto') {
            $host = strtolower((string) parse_url($settings['rpc_url'] ?? '', PHP_URL_HOST));
            $profile = 'requests';
            foreach (['alchemy.com' => 'alchemy', 'quiknode.pro' => 'quicknode', 'infura.io' => 'infura'] as $domain => $candidate) {
                if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                    $profile = $candidate;
                    break;
                }
            }
        }
        if (! in_array($profile, ['requests', 'alchemy', 'quicknode', 'infura'], true)) {
            throw new InvalidArgumentException('Unknown API accounting profile.');
        }
        $group = $settings['rate_group'] ?? ($profile === 'requests' ? $chain.'-'.$provider : $profile);
        if (! is_string($group) || ! preg_match('/^[a-z][a-z0-9-]{0,39}$/D', $group)) {
            throw new InvalidArgumentException('Invalid API pacing group.');
        }
        $rate = self::integer($settings['rate_per_second'] ?? (in_array($profile, ['alchemy', 'infura'], true) ? 200 : 0), 1000000);
        $target = self::integer($settings['daily_target'] ?? match ($profile) {
            'alchemy' => 800000,
            'quicknode' => 250000,
            'infura' => 2400000,
            default => $chain === 'tron' ? 75000 : 0,
        }, 1000000000000);

        return new self($group, $profile, $rate, $target, $chain === 'tron' ? 30 : 20);
    }

    /** Fixed method labels only; never retain arbitrary caller text. */
    public static function method(string $method): string
    {
        return in_array($method, ['eth_chainId', 'eth_getBlockByNumber', 'eth_getLogs', 'eth_getTransactionReceipt',
            '/walletsolidity/getnowblock', '/walletsolidity/getblockbynum', '/walletsolidity/gettransactioninfobyid',
            '/walletsolidity/gettransactioninfobyblocknum'], true) ? $method : 'other';
    }

    public function billedUnits(string $method): int
    {
        return match ($this->profile) {
            'alchemy' => match ($method) {
                'eth_chainId' => 0, 'eth_getLogs' => 60, default => 20,
            },
            'quicknode' => $this->quicknodeCost,
            'infura' => match ($method) {
                'eth_chainId' => 5, 'eth_getLogs' => 255, default => 80,
            },
            default => 1,
        };
    }

    public function pacingUnits(string $method): int
    {
        return $this->profile === 'alchemy' && $method === 'eth_chainId' ? 5 : ($this->profile === 'requests' || $this->profile === 'quicknode' ? 1 : $this->billedUnits($method));
    }

    public function unit(): string
    {
        return match ($this->profile) {
            'alchemy' => 'CU', 'quicknode', 'infura' => 'credits', default => 'requests',
        };
    }

    private static function integer(mixed $value, int $maximum): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $maximum]]);
        if ($integer === false) {
            throw new InvalidArgumentException('Invalid API pacing or usage target.');
        }

        return $integer;
    }
}
