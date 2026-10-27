<?php

namespace App\Monitoring;

final class ScanCadence
{
    public static function remainingMicros(string $chain, bool $idle, bool $catchingUp, int $idleInterval, int $elapsedMicros): int
    {
        $minimum = $idle ? $idleInterval : ($catchingUp ? 1 : max(1, min(60, (int) config('blockchain.chains.'.$chain.'.scan_min_interval_seconds', 1))));

        return max(0, $minimum * 1000000 - $elapsedMicros);
    }
}
