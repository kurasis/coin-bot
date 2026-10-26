<?php

namespace App\Operations;

use RuntimeException;

class Heartbeat
{
    public function record(string $worker): void
    {
        $path = $this->path($worker);
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0700, true) && ! is_dir(dirname($path))) {
            throw new RuntimeException('Cannot create heartbeat directory.');
        }
        $temporary = tempnam(dirname($path), '.heartbeat-');
        if ($temporary === false || file_put_contents($temporary, json_encode([
            'worker' => $worker, 'at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR)) === false || ! rename($temporary, $path)) {
            throw new RuntimeException('Cannot persist worker heartbeat.');
        }
        chmod($path, 0600);
    }

    public function lastSeen(string $worker): ?string
    {
        $path = $this->path($worker);
        if (! is_file($path)) {
            return null;
        }
        $record = json_decode((string) file_get_contents($path), true);

        return is_array($record) && is_string($record['at'] ?? null) ? $record['at'] : null;
    }

    private function path(string $worker): string
    {
        if (! preg_match('/^[a-z][a-z0-9:_-]{0,63}$/D', $worker)) {
            throw new RuntimeException('Invalid worker heartbeat identifier.');
        }

        return rtrim((string) config('operations.state_directory'), '/').'/heartbeats/'.$worker.'.json';
    }
}
