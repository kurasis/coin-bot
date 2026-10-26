<?php

namespace App\Console\Commands;

use App\Operations\MaintenanceGuard;
use Illuminate\Console\Command;
use Throwable;

class OpsResume extends Command
{
    protected $signature = 'ops:resume {--force}';

    protected $description = 'Explicitly resume operations after the operator recovery checklist';

    public function handle(MaintenanceGuard $maintenance): int
    {
        if (! $this->option('force')) {
            $this->error('Review the recovery checklist before resuming, then supply --force.');

            return self::INVALID;
        }
        try {
            $maintenance->resume();
        } catch (Throwable) {
            $this->error('Cannot resume while Laravel maintenance mode remains active.');

            return self::FAILURE;
        }
        $this->info('Operational pause cleared. Only valid newly authorized recipients may receive notifications.');

        return self::SUCCESS;
    }
}
