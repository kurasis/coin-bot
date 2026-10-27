<?php

namespace App\Monitoring;

use App\Operations\MaintenanceGuard;
use Illuminate\Support\Facades\DB;

final class MonitoringReadiness
{
    public function state(string $chain, bool $early = false): string
    {
        if (app()->isDownForMaintenance() || app(MaintenanceGuard::class)->isPaused()) {
            return 'maintenance';
        }
        if (! DB::table('scan_cursors')->where('chain', $chain)->where('role', 'primary')->where('provider', 'primary')->exists()) {
            return 'setup';
        }
        if (DB::table('scan_cursors')->where('chain', $chain)->whereIn('role', ['primary', 'reconciliation'])->whereNotNull('paused_at')->exists()
            || ($early && DB::table('ethereum_early_state')->whereNotNull('paused_at')->exists())) {
            return 'safety_paused';
        }

        return 'ready';
    }
}
