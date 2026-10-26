<?php

namespace App\Console\Commands;

use App\Operations\BackupService;
use Illuminate\Console\Command;
use Throwable;

class OpsRestore extends Command
{
    protected $signature = 'ops:restore {artifact} {--force} {--workers-stopped} {--operator=}';

    protected $description = 'Restore validated backup with all access invalidated; operations remain paused';

    public function handle(BackupService $backups): int
    {
        if (! $this->option('force') || ! $this->option('workers-stopped')) {
            $this->error('Restore requires --force and --workers-stopped after fencing all writers and pausing operations.');

            return self::INVALID;
        }
        try {
            $backups->restore((string) $this->argument('artifact'), true, $this->option('operator'));
            $this->info('Validated restore completed. Operations remain paused; all restored keys and grants were invalidated.');
            $this->warn('Verify data, issue fresh access, and explicitly resume only after the recovery checklist.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Restore failed. Operations remain paused; inspect the protected backup and current database.');

            return self::FAILURE;
        }
    }
}
