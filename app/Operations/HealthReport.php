<?php

namespace App\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class HealthReport
{
    public function __construct(
        private readonly BackupService $backups = new BackupService,
        private readonly MaintenanceGuard $maintenance = new MaintenanceGuard,
        private readonly Heartbeat $heartbeat = new Heartbeat,
    ) {}

    public function inspect(): array
    {
        $issues = [];
        $metrics = [];
        $mode = config('operations.backup_mode', 'offhost');
        $metrics['backup_mode'] = in_array($mode, ['local', 'offhost'], true) ? $mode : 'unknown';
        if ($metrics['backup_mode'] === 'unknown') {
            $issues[] = 'backup_mode_invalid';
        }
        if ($this->maintenance->isPaused()) {
            $issues[] = 'operations_paused';
        }
        try {
            DB::select('select 1');
            $metrics['paused_cursors'] = DB::table('scan_cursors')->whereNotNull('paused_at')->count();
            if ($metrics['paused_cursors'] > 0) {
                $issues[] = 'paused_cursor';
            }
            $cursors = DB::table('scan_cursors')->where('role', 'primary')->get(['id', 'chain', 'last_block', 'updated_at']);
            foreach ($cursors as $cursor) {
                $age = $this->age($cursor->updated_at);
                $metrics['cursor_age_'.$cursor->chain] = $age;
                if ($age === null || $age > (int) config('operations.max_cursor_age_seconds', 1200)) {
                    $issues[] = 'stale_cursor_'.$cursor->chain;
                }
                $range = DB::table('scan_ranges')->where('cursor_id', $cursor->id)
                    ->where('status', 'completed')->whereNull('reconciled_at')
                    ->selectRaw('COUNT(*) AS pending_count, MIN(COALESCE(completed_at, created_at)) AS oldest_completed_at')->first();
                $pending = (int) $range->pending_count;
                $unreconciledAge = $pending === 0 ? 0 : $this->age($range->oldest_completed_at);
                $watermark = DB::table('scan_cursors')->where('chain', $cursor->chain)
                    ->where('role', 'reconciliation')->max('last_block');
                $metrics['unreconciled_ranges_'.$cursor->chain] = $pending;
                $metrics['oldest_unreconciled_age_'.$cursor->chain] = $unreconciledAge;
                $metrics['reconciled_through_'.$cursor->chain] = $watermark === null ? null : (int) $watermark;
                $metrics['reconciliation_lag_blocks_'.$cursor->chain] = $watermark === null
                    ? null : max(0, (int) $cursor->last_block - (int) $watermark);
                $maximumAge = (int) config('operations.max_unreconciled_age_seconds', 1800);
                if ($pending > 0 && ($unreconciledAge === null || $unreconciledAge > $maximumAge)) {
                    $issues[] = 'reconciliation_lag_'.$cursor->chain;
                }
                if ($watermark === null) {
                    $oldestCompleted = DB::table('scan_ranges')->where('cursor_id', $cursor->id)->where('status', 'completed')
                        ->selectRaw('MIN(COALESCE(completed_at, created_at)) AS oldest_completed_at')->first();
                    $oldestCompletedAge = $this->age($oldestCompleted->oldest_completed_at);
                    if ($oldestCompletedAge !== null && $oldestCompletedAge > $maximumAge) {
                        $issues[] = 'reconciliation_watermark_missing_'.$cursor->chain;
                    }
                }
            }
            if (Schema::hasTable('provider_health')) {
                $metrics['provider_circuits_open'] = DB::table('provider_health')->where('opens_until', '>', now())->count();
                if ($metrics['provider_circuits_open'] > 0) {
                    $issues[] = 'provider_circuit_open';
                }
            }
            if (Schema::hasTable('reconciliation_checks')) {
                $metrics['reconciliation_conflicts'] = DB::table('reconciliation_checks')->where('status', 'conflict')->count();
                if ($metrics['reconciliation_conflicts'] > 0) {
                    $issues[] = 'reconciliation_conflict';
                }
            }
            $oldest = DB::table('notification_outbox')->whereIn('status', ['pending', 'retry', 'processing'])->min('created_at');
            $metrics['oldest_pending_seconds'] = $oldest === null ? 0 : $this->age($oldest);
            if (($metrics['oldest_pending_seconds'] ?? PHP_INT_MAX) > (int) config('operations.max_outbox_age_seconds', 300)) {
                $issues[] = 'delivery_backlog';
            }
            $metrics['failed_deliveries'] = DB::table('notification_outbox')->where('status', 'failed')->count();
            if ($metrics['failed_deliveries'] > 0) {
                $issues[] = 'failed_deliveries';
            }
            $oldestReply = DB::table('telegram_replies')->whereIn('status', ['pending', 'retry', 'processing'])->min('created_at');
            $metrics['oldest_pending_reply_seconds'] = $oldestReply === null ? 0 : $this->age($oldestReply);
            $metrics['failed_replies'] = DB::table('telegram_replies')->where('status', 'failed')->count();
            if (($metrics['oldest_pending_reply_seconds'] ?? PHP_INT_MAX) > (int) config('operations.max_outbox_age_seconds', 300) || $metrics['failed_replies'] > 0) {
                $issues[] = 'telegram_reply_failure_or_backlog';
            }
            $metrics['suspended_destinations'] = DB::table('chat_destinations')->whereNotNull('suspended_at')->count();
            if ($metrics['suspended_destinations'] > 0) {
                $issues[] = 'suspended_destinations';
            }
            $database = DB::connection()->getDatabaseName();
            if (is_string($database) && $database !== ':memory:') {
                $free = disk_free_space(dirname($database));
                $metrics['disk_free_bytes'] = $free === false ? null : (int) $free;
                $metrics['wal_bytes'] = is_file($database.'-wal') ? filesize($database.'-wal') : 0;
                if ($free === false || $free < (int) config('operations.minimum_free_bytes', 268435456)) {
                    $issues[] = 'disk_space_low';
                }
                if ($metrics['wal_bytes'] > (int) config('operations.max_wal_bytes', 134217728)) {
                    $issues[] = 'wal_growth';
                }
            }
        } catch (Throwable) {
            $issues[] = 'database_unavailable';
        }
        $backup = $this->backups->lastBackup();
        $metrics['backup_age_seconds'] = $this->age($backup['completed_at'] ?? null);
        if (config('operations.require_backup', true)
            && ($backup === null || ! is_file($backup['artifact'] ?? '') || ($metrics['backup_age_seconds'] ?? PHP_INT_MAX) > (int) config('operations.max_backup_age_seconds', 180))) {
            $issues[] = 'backup_missing_or_stale';
        }
        foreach (config('operations.required_workers', []) as $worker) {
            $seen = $this->heartbeat->lastSeen($worker);
            $age = $this->age($seen);
            $metrics['heartbeat_age_'.$worker] = $age;
            if ($age === null || $age > (int) config('operations.max_heartbeat_age_seconds', 120)) {
                $issues[] = 'heartbeat_missing_or_stale_'.$worker;
            }
        }

        return ['status' => $issues === [] ? 'ok' : 'degraded', 'issues' => array_values(array_unique($issues)), 'metrics' => $metrics];
    }

    private function age(?string $timestamp): ?int
    {
        if ($timestamp === null) {
            return null;
        }
        try {
            $seconds = (int) CarbonImmutable::parse($timestamp)->diffInSeconds(now(), false);

            return $seconds < 0 ? null : $seconds;
        } catch (Throwable) {
            return null;
        }
    }
}
