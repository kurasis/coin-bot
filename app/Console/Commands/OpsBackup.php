<?php

namespace App\Console\Commands;

use App\Operations\BackupService;
use App\Operations\Heartbeat;
use Illuminate\Console\Command;
use Throwable;

class OpsBackup extends Command
{
    protected $signature = 'ops:backup {--directory=} {--loop} {--interval=60}';

    protected $description = 'Create authenticated encrypted consistent SQLite backups';

    public function handle(BackupService $backups, Heartbeat $heartbeat): int
    {
        $interval = filter_var($this->option('interval'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 10, 'max_range' => 86400]]);
        if ($interval === false) {
            $this->error('Backup interval must be between 10 and 86400 seconds.');

            return self::INVALID;
        }
        do {
            try {
                $backups->create($this->option('directory'));
                $heartbeat->record('backups');
                $this->info('Encrypted consistent backup completed. Verify off-host replication separately.');
            } catch (Throwable) {
                $this->error('Backup failed; the last successful backup status was preserved.');

                return self::FAILURE;
            }
            if ($this->option('loop')) {
                sleep($interval);
            }
        } while ($this->option('loop'));

        return self::SUCCESS;
    }
}
