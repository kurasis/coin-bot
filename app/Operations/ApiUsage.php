<?php

namespace App\Operations;

use RuntimeException;
use Throwable;

/** Local estimates and cross-process pacing; never stores endpoints, credentials or request parameters. */
class ApiUsage
{
    public function admit(ApiPolicy $policy, string $method): void
    {
        $method = ApiPolicy::method($method);
        $totalWait = 0;
        do {
            $wait = $this->locked($policy, function (array &$state) use ($policy, $method, $totalWait): int {
                $now = $this->nowMicros();
                if ($policy->rate > 0 && $state['next_us'] > $now) {
                    $delay = $state['next_us'] - $now;
                    if ($delay > 30_000_000) {
                        throw new RuntimeException('API pacing queue is full.');
                    }

                    return $delay;
                }
                if ($policy->rate > 0) {
                    $state['next_us'] = $now + (int) ceil($policy->pacingUnits($method) * 1_000_000 / $policy->rate);
                }
                $day = $this->day($state);
                $state['days'][$day]['requests']++;
                $state['days'][$day]['estimated_units'] += $policy->billedUnits($method);
                $state['days'][$day]['wait_us'] += $totalWait;
                $state['days'][$day]['methods'][$method] = ($state['days'][$day]['methods'][$method] ?? 0) + 1;

                return 0;
            });
            // Release the lock before waiting, then compete for a fresh slot.
            // A delayed or cancelled worker cannot leave reserved future slots.
            if ($wait > 0) {
                $this->sleepMicros($wait);
                $totalWait += $wait;
            }
        } while ($wait > 0);
    }

    public function failed(ApiPolicy $policy): void
    {
        $this->locked($policy, function (array &$state): void {
            $day = $this->day($state);
            $state['days'][$day]['transport_failures']++;
        });
    }

    public function report(?string $date = null, bool $methods = true): array
    {
        $date ??= gmdate('Y-m-d');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || $parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new RuntimeException('Use a valid UTC date in YYYY-MM-DD format.');
        }
        $report = ['enabled' => (bool) config('operations.api_usage_enabled', true), 'date_utc' => $date, 'local_estimates' => true, 'providers' => []];
        if (! $report['enabled']) {
            return $report;
        }
        foreach (['ethereum', 'tron'] as $chain) {
            foreach (['primary', 'secondary'] as $role) {
                $settings = config('blockchain.chains.'.$chain.'.providers.'.$role, []);
                if (empty($settings['rpc_url'])) {
                    continue;
                }
                $policy = ApiPolicy::from($settings, $chain, $role);
                $state = $this->locked($policy, static fn (array &$state): array => $state, false);
                $day = $state['days'][$date] ?? [];
                $units = $day['estimated_units'] ?? 0;
                $record = ['profile' => $policy->profile, 'requests' => $day['requests'] ?? 0,
                    'estimated_units' => $units, 'unit' => $policy->unit(), 'daily_target' => $policy->dailyTarget,
                    'over_daily_target' => $policy->dailyTarget > 0 && $units > $policy->dailyTarget,
                    'rate_per_second' => $policy->rate, 'transport_failures' => $day['transport_failures'] ?? 0,
                    'wait_ms' => intdiv($day['wait_us'] ?? 0, 1000),
                    'first_attempt_utc' => isset($day['first_us']) ? gmdate('Y-m-d\TH:i:s\Z', intdiv($day['first_us'], 1000000)) : null];
                if ($methods) {
                    $record['methods'] = $day['methods'] ?? [];
                }
                // Roles sharing a pacing group show the same account aggregate.
                $report['providers'][$chain.'_'.$role] = $record;
            }
        }

        return $report;
    }

    protected function nowMicros(): int
    {
        return (int) floor(microtime(true) * 1_000_000);
    }

    protected function sleepMicros(int $microseconds): void
    {
        $until = $this->nowMicros() + $microseconds;
        do {
            usleep(min(500000, max(1, $until - $this->nowMicros())));
        } while ($this->nowMicros() < $until);
    }

    private function day(array &$state): string
    {
        $now = $this->nowMicros();
        $day = gmdate('Y-m-d', intdiv($now, 1000000));
        $state['days'][$day] ??= ['requests' => 0, 'estimated_units' => 0, 'wait_us' => 0, 'transport_failures' => 0, 'first_us' => $now, 'methods' => []];
        $oldest = gmdate('Y-m-d', intdiv($now, 1000000) - 34 * 86400);
        $state['days'] = array_filter($state['days'], static fn (string $key): bool => $key >= $oldest, ARRAY_FILTER_USE_KEY);

        return $day;
    }

    private function locked(ApiPolicy $policy, callable $operation, bool $write = true): mixed
    {
        $lock = null;
        $temporary = null;
        try {
            $directory = rtrim((string) config('operations.state_directory'), '/').'/api';
            if (is_link($directory)) {
                throw new RuntimeException;
            }
            $state = ['format' => 1, 'profile' => $policy->profile, 'next_us' => 0, 'days' => []];
            if (! is_dir($directory)) {
                if (! $write) {
                    return $operation($state);
                }
                if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                    throw new RuntimeException;
                }
            }
            // The stable lock inode is separate from the atomically replaced data file.
            $lockPath = $directory.'/'.$policy->group.'.lock';
            if (is_link($lockPath)) {
                throw new RuntimeException;
            }
            $lock = fopen($lockPath, 'c+b');
            if ($lock === false || ! flock($lock, $write ? LOCK_EX : LOCK_SH)) {
                throw new RuntimeException;
            }
            $stat = fstat($lock);
            $entry = lstat($lockPath);
            if ($stat === false || $entry === false || $stat['ino'] !== $entry['ino'] || $stat['dev'] !== $entry['dev']
                || $stat['nlink'] !== 1 || ($stat['mode'] & 0170000) !== 0100000) {
                throw new RuntimeException;
            }
            chmod($lockPath, 0600);
            $path = $directory.'/'.$policy->group.'.json';
            if (is_link($path)) {
                throw new RuntimeException;
            }
            if (file_exists($path)) {
                $raw = file_get_contents($path, length: 524289);
                if (! is_string($raw) || strlen($raw) > 524288) {
                    throw new RuntimeException;
                }
                $state = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
                $this->validate($state, $policy);
            }
            $result = $operation($state);
            if ($write) {
                $temporary = tempnam($directory, '.api-');
                $encoded = json_encode($state, JSON_THROW_ON_ERROR);
                if ($temporary === false || file_put_contents($temporary, $encoded) !== strlen($encoded)
                    || ! chmod($temporary, 0600) || ! rename($temporary, $path)) {
                    throw new RuntimeException;
                }
                $temporary = null;
            }

            return $result;
        } catch (Throwable) {
            throw new RuntimeException('Local API pacing and usage state is unavailable.');
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function validate(mixed $state, ApiPolicy $policy): void
    {
        if (! is_array($state) || ($state['format'] ?? null) !== 1 || ($state['profile'] ?? null) !== $policy->profile
            || ! is_int($state['next_us'] ?? null) || $state['next_us'] < 0 || ! is_array($state['days'] ?? null) || count($state['days']) > 35) {
            throw new RuntimeException;
        }
        foreach ($state['days'] as $day => $record) {
            if (! is_string($day) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) || ! is_array($record) || ! is_array($record['methods'] ?? null)) {
                throw new RuntimeException;
            }
            foreach (['requests', 'estimated_units', 'wait_us', 'transport_failures', 'first_us'] as $field) {
                if (! is_int($record[$field] ?? null) || $record[$field] < 0) {
                    throw new RuntimeException;
                }
            }
            foreach ($record['methods'] as $method => $count) {
                if (ApiPolicy::method($method) !== $method || ! is_int($count) || $count < 0) {
                    throw new RuntimeException;
                }
            }
        }
    }
}
