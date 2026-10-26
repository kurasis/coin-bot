<?php

namespace App\Operations;

use RuntimeException;

class MaintenanceGuard
{
    public function isPaused(): bool
    {
        return is_file($this->marker()) || app()->isDownForMaintenance();
    }

    public function assertRunning(): void
    {
        if ($this->isPaused()) {
            throw new RuntimeException('Operations are paused; no enrollment, scanning, or delivery is permitted.');
        }
    }

    public function pause(string $reason = 'Operator maintenance.'): void
    {
        $directory = dirname($this->marker());
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create operational state directory.');
        }
        $temporary = tempnam($directory, '.pause-');
        if ($temporary === false || file_put_contents($temporary, json_encode([
            'paused_at' => now()->toIso8601String(), 'reason' => $reason,
        ], JSON_THROW_ON_ERROR)) === false || ! rename($temporary, $this->marker())) {
            throw new RuntimeException('Cannot persist maintenance state.');
        }
        chmod($this->marker(), 0600);
    }

    public function resume(): void
    {
        if (app()->isDownForMaintenance()) {
            throw new RuntimeException('Laravel maintenance remains active; run artisan up after verification.');
        }
        if (is_file($this->marker()) && ! unlink($this->marker())) {
            throw new RuntimeException('Cannot clear operational maintenance state.');
        }
    }

    private function marker(): string
    {
        return rtrim((string) config('operations.state_directory'), '/').'/paused';
    }
}
