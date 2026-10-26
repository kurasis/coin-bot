<?php

namespace App\Console\Commands;

use App\Operations\MaintenanceGuard;
use Illuminate\Console\Command;

class OpsPause extends Command
{
    protected $signature = 'ops:pause';

    protected $description = 'Persistently pause enrollment, scanning, backfill, and notification workers';

    public function handle(MaintenanceGuard $maintenance): int
    {
        $maintenance->pause();
        $this->info('Operations paused. Stop and fence all writers before any restore.');

        return self::SUCCESS;
    }
}
