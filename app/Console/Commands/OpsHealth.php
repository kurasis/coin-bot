<?php

namespace App\Console\Commands;

use App\Operations\HealthReport;
use Illuminate\Console\Command;

class OpsHealth extends Command
{
    protected $signature = 'ops:health';

    protected $description = 'Report operational readiness without exposing wallet, chat, or credential data';

    public function handle(HealthReport $health): int
    {
        $report = $health->inspect();
        $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $report['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
