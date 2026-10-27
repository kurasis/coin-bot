<?php

namespace App\Console\Commands;

use App\Operations\ApiUsage;
use Illuminate\Console\Command;
use Throwable;

final class OpsApiUsage extends Command
{
    protected $signature = 'ops:api-usage {--date= : UTC date YYYY-MM-DD; defaults to today}';

    protected $description = 'Report local API attempts, estimated billing units and shared pacing without credentials';

    public function handle(ApiUsage $usage): int
    {
        try {
            $this->line(json_encode($usage->report($this->option('date')), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->line('{"error":"api_usage_unavailable","hint":"Check the UTC date and private operational storage."}');

            return self::FAILURE;
        }
    }
}
