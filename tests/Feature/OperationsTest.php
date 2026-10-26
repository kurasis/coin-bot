<?php

namespace Tests\Feature;

use App\Access\AccessDenied;
use App\Access\AccessService;
use App\Operations\BackupService;
use App\Operations\HealthReport;
use App\Operations\Heartbeat;
use App\Operations\MaintenanceGuard;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/coin-bot-operations-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
        $database = $this->directory.'/database.sqlite';
        touch($database);
        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $database,
            'operations.state_directory' => $this->directory.'/state',
            'operations.backup_directory' => $this->directory.'/backups',
            'operations.required_workers' => [],
            'operations.require_backup' => true,
            'operations.minimum_free_bytes' => 1,
        ]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        DB::purge('operations_restore');
        $this->removeDirectory($this->directory);
        parent::tearDown();
    }

    public function test_online_backup_is_encrypted_and_restore_invalidates_consumed_keys_and_grants(): void
    {
        $this->freezeTime();
        $access = app(AccessService::class);
        $issued = $access->issue();
        $artifact = app(BackupService::class)->create();
        $encrypted = file_get_contents($artifact);
        $this->assertStringStartsWith("COINBOT-BACKUP-1\n", $encrypted);
        $this->assertStringNotContainsString('SQLite format 3', $encrypted);
        $this->assertStringNotContainsString($issued->token, $encrypted);
        $this->assertSame(0600, fileperms($artifact) & 0777);

        $activated = $access->activate($issued->token, '12345678');
        $access->revokeGrant($activated->grantId);
        $access->revokeKey($issued->id);
        app(MaintenanceGuard::class)->pause();
        app(BackupService::class)->restore($artifact, workersStopped: true, operatorId: 'restore-test');
        $this->assertTrue(app(MaintenanceGuard::class)->isPaused());
        $this->assertSame(1, DB::table('access_keys')->count());
        $this->assertNotNull(DB::table('access_keys')->find($issued->id)->revoked_at);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'access.restore_invalidated')->count());
        try {
            $access->activate($issued->token, '98765432');
            $this->fail('A restored outstanding key must not become reusable.');
        } catch (AccessDenied) {
            $this->assertSame(0, DB::table('access_grants')->count());
        }
    }

    public function test_restored_pre_revocation_grant_stays_revoked_and_public_wallet_data_survives(): void
    {
        $access = app(AccessService::class);
        $issued = $access->issue();
        $activated = $access->activate($issued->token, '11111111');
        DB::table('wallets')->insert([
            'chain' => 'ethereum', 'address' => '0x'.str_repeat('1', 40),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('telegram_replies')->insert([
            'update_id' => 4321, 'chat_id' => '11111111', 'user_id' => $activated->userId,
            'text' => 'Access activated.', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $artifact = app(BackupService::class)->create();
        $access->revokeGrant($activated->grantId);
        app(MaintenanceGuard::class)->pause();
        app(BackupService::class)->restore($artifact, workersStopped: true);
        $this->assertNull($access->activeGrant($activated->userId));
        $this->assertNotNull(DB::table('access_grants')->find($activated->grantId)->revoked_at);
        $this->assertSame(1, DB::table('wallets')->count());
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame('cancelled', DB::table('telegram_replies')->value('status'));
    }

    public function test_tampered_ciphertext_cannot_replace_live_database(): void
    {
        $artifact = app(BackupService::class)->create();
        $bytes = file_get_contents($artifact);
        $bytes[strlen($bytes) - 2] = chr(ord($bytes[strlen($bytes) - 2]) ^ 1);
        file_put_contents($artifact, $bytes);
        DB::table('wallets')->insert([
            'chain' => 'tron', 'address' => 'preserved-live-wallet', 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(MaintenanceGuard::class)->pause();
        try {
            app(BackupService::class)->restore($artifact, workersStopped: true);
            $this->fail('Tampered backup must fail authenticated restore.');
        } catch (RuntimeException) {
            $this->assertSame('preserved-live-wallet', DB::table('wallets')->value('address'));
            $this->assertTrue(app(MaintenanceGuard::class)->isPaused());
        }
    }

    public function test_wrong_key_and_truncated_artifacts_fail_closed(): void
    {
        $artifact = app(BackupService::class)->create();
        $originalKey = config('app.key');
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        app(MaintenanceGuard::class)->pause();
        try {
            app(BackupService::class)->restore($artifact, workersStopped: true);
            $this->fail('Wrong encryption key must fail.');
        } catch (RuntimeException) {
            $this->assertSame(0, DB::table('access_keys')->count());
        }
        config(['app.key' => $originalKey]);
        file_put_contents($artifact, substr(file_get_contents($artifact), 0, -10));
        $this->expectException(RuntimeException::class);
        app(BackupService::class)->restore($artifact, workersStopped: true);
    }

    public function test_restore_requires_pause_and_explicit_stopped_writer_confirmation(): void
    {
        $artifact = app(BackupService::class)->create();
        try {
            app(BackupService::class)->restore($artifact, workersStopped: true);
            $this->fail('Restore while running must fail.');
        } catch (RuntimeException) {
            $this->assertFalse(app(MaintenanceGuard::class)->isPaused());
        }
        app(MaintenanceGuard::class)->pause();
        $this->expectException(RuntimeException::class);
        app(BackupService::class)->restore($artifact, workersStopped: false);
    }

    public function test_health_detects_stale_backups_and_missing_heartbeats(): void
    {
        $this->freezeTime();
        $health = app(HealthReport::class);
        $this->assertContains('backup_missing_or_stale', $health->inspect()['issues']);
        app(BackupService::class)->create();
        $this->assertSame('ok', $health->inspect()['status']);
        config(['operations.required_workers' => ['telegram-poll']]);
        $this->assertContains('heartbeat_missing_or_stale_telegram-poll', $health->inspect()['issues']);
        app(Heartbeat::class)->record('telegram-poll');
        $this->assertSame('ok', $health->inspect()['status']);
        $this->travel(5)->minutes();
        $issues = $health->inspect()['issues'];
        $this->assertContains('backup_missing_or_stale', $issues);
        $this->assertContains('heartbeat_missing_or_stale_telegram-poll', $issues);
        app(MaintenanceGuard::class)->pause();
        $this->assertContains('operations_paused', $health->inspect()['issues']);
    }

    public function test_health_detects_delivery_lag_paused_cursors_and_capacity_limits(): void
    {
        $this->freezeTime();
        app(BackupService::class)->create();
        $destinationId = DB::table('chat_destinations')->insertGetId([
            'chat_id' => '123', 'kind' => 'private', 'enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $transferId = DB::table('transfers')->insertGetId([
            'chain' => 'ethereum', 'transaction_hash' => '0x'.str_repeat('a', 64),
            'receipt_log_position' => 0, 'contract_address' => '0x'.str_repeat('1', 40),
            'from_address' => '0x'.str_repeat('2', 40), 'to_address' => '0x'.str_repeat('3', 40),
            'amount_raw' => '1000000', 'block_number' => 1, 'block_hash' => '0x'.str_repeat('b', 64),
            'block_timestamp' => now()->timestamp, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('notification_outbox')->insert([
            'transfer_id' => $transferId, 'destination_id' => $destinationId,
            'notification_kind' => 'transfer', 'status' => 'retry',
            'created_at' => now()->subMinutes(10), 'updated_at' => now(),
        ]);
        DB::table('scan_cursors')->insert([
            'chain' => 'ethereum', 'role' => 'primary', 'provider' => 'primary',
            'last_block' => 1, 'paused_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('telegram_replies')->insert([
            'update_id' => 321, 'chat_id' => '123', 'text' => 'Activation required.',
            'status' => 'failed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        config(['operations.minimum_free_bytes' => PHP_INT_MAX]);
        $report = app(HealthReport::class)->inspect();
        $this->assertContains('paused_cursor', $report['issues']);
        $this->assertContains('delivery_backlog', $report['issues']);
        $this->assertContains('telegram_reply_failure_or_backlog', $report['issues']);
        $this->assertContains('disk_space_low', $report['issues']);
        $this->assertSame(600, $report['metrics']['oldest_pending_seconds']);
    }

    public function test_fresh_workers_and_primary_progress_do_not_hide_overdue_reconciliation(): void
    {
        $this->freezeTime();
        config([
            'operations.max_unreconciled_age_seconds' => 1800,
            'operations.required_workers' => ['scan-ethereum', 'reconcile-ethereum'],
        ]);
        $setId = DB::table('watch_sets')->insertGetId([
            'chain' => 'ethereum', 'version' => 1, 'cutover_block' => 99,
            'activated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $primaryId = DB::table('scan_cursors')->insertGetId([
            'chain' => 'ethereum', 'role' => 'primary', 'provider' => 'primary', 'watch_set_id' => $setId,
            'last_block' => 119, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('scan_cursors')->insert([
            'chain' => 'ethereum', 'role' => 'reconciliation', 'provider' => 'secondary', 'watch_set_id' => $setId,
            'last_block' => 99, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $rangeId = DB::table('scan_ranges')->insertGetId([
            'cursor_id' => $primaryId, 'watch_set_id' => $setId, 'from_block' => 100, 'to_block' => 119,
            'status' => 'completed', 'completed_at' => now()->subMinutes(31),
            'created_at' => now()->subMinutes(31), 'updated_at' => now(),
        ]);
        app(Heartbeat::class)->record('scan-ethereum');
        app(Heartbeat::class)->record('reconcile-ethereum');
        app(BackupService::class)->create();
        $report = app(HealthReport::class)->inspect();
        $this->assertSame('degraded', $report['status']);
        $this->assertSame(['reconciliation_lag_ethereum'], $report['issues']);
        $this->assertSame(0, $report['metrics']['cursor_age_ethereum']);
        $this->assertSame(0, $report['metrics']['heartbeat_age_reconcile-ethereum']);
        $this->assertSame(1860, $report['metrics']['oldest_unreconciled_age_ethereum']);
        $this->assertSame(99, $report['metrics']['reconciled_through_ethereum']);
        $this->assertSame(20, $report['metrics']['reconciliation_lag_blocks_ethereum']);

        DB::table('scan_cursors')->where('role', 'reconciliation')->delete();
        $this->assertContains('reconciliation_watermark_missing_ethereum', app(HealthReport::class)->inspect()['issues']);
        DB::table('scan_ranges')->where('id', $rangeId)->update(['reconciled_at' => now()]);
        $this->assertContains('reconciliation_watermark_missing_ethereum', app(HealthReport::class)->inspect()['issues']);
        DB::table('scan_cursors')->insert([
            'chain' => 'ethereum', 'role' => 'reconciliation', 'provider' => 'secondary', 'watch_set_id' => $setId,
            'last_block' => 119, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame('ok', app(HealthReport::class)->inspect()['status']);
    }

    public function test_private_backfill_and_recent_primary_work_do_not_raise_reconciliation_lag(): void
    {
        $this->freezeTime();
        foreach (['primary', 'backfill'] as $index => $role) {
            $setId = DB::table('watch_sets')->insertGetId([
                'chain' => 'tron', 'version' => $index + 1, 'cutover_block' => 99,
                'activated_at' => $role === 'primary' ? now() : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $cursorId = DB::table('scan_cursors')->insertGetId([
                'chain' => 'tron', 'role' => $role, 'provider' => $role, 'watch_set_id' => $setId,
                'last_block' => 119, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('scan_ranges')->insert([
                'cursor_id' => $cursorId, 'watch_set_id' => $setId, 'from_block' => 100, 'to_block' => 119,
                'status' => 'completed', 'completed_at' => $role === 'primary' ? now()->subMinute() : now()->subDays(2),
                'created_at' => now()->subDays(2), 'updated_at' => now(),
            ]);
        }
        app(BackupService::class)->create();
        $report = app(HealthReport::class)->inspect();
        $this->assertSame('ok', $report['status']);
        $this->assertSame(1, $report['metrics']['unreconciled_ranges_tron']);
        $this->assertSame(60, $report['metrics']['oldest_unreconciled_age_tron']);
        $this->assertNull($report['metrics']['reconciled_through_tron']);
    }

    public function test_open_provider_circuits_and_unresolved_conflicts_raise_health_issues(): void
    {
        $this->freezeTime();
        app(BackupService::class)->create();
        DB::table('provider_health')->insert([
            'chain' => 'tron', 'provider' => 'secondary', 'failure_count' => 1,
            'opens_until' => now()->addMinute(), 'last_http_status' => 429,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $setId = DB::table('watch_sets')->insertGetId([
            'chain' => 'tron', 'version' => 1, 'cutover_block' => 99,
            'activated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cursorId = DB::table('scan_cursors')->insertGetId([
            'chain' => 'tron', 'role' => 'primary', 'provider' => 'primary', 'watch_set_id' => $setId,
            'last_block' => 119, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $rangeId = DB::table('scan_ranges')->insertGetId([
            'cursor_id' => $cursorId, 'watch_set_id' => $setId, 'from_block' => 100, 'to_block' => 119,
            'status' => 'completed', 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('reconciliation_checks')->insert([
            'range_id' => $rangeId, 'provider' => 'secondary', 'status' => 'conflict',
            'failure_code' => 'boundary_hash_mismatch', 'last_checked_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $report = app(HealthReport::class)->inspect();
        $this->assertContains('provider_circuit_open', $report['issues']);
        $this->assertContains('reconciliation_conflict', $report['issues']);
        $this->assertSame(1, $report['metrics']['provider_circuits_open']);
        $this->assertSame(1, $report['metrics']['reconciliation_conflicts']);
        DB::table('provider_health')->update(['opens_until' => now()->subSecond()]);
        DB::table('reconciliation_checks')->update(['status' => 'matched']);
        $this->assertSame('ok', app(HealthReport::class)->inspect()['status']);
    }

    public function test_backup_retention_and_public_directory_protection(): void
    {
        config(['operations.backup_retention' => 2]);
        for ($i = 0; $i < 3; $i++) {
            app(BackupService::class)->create();
            $this->travel(1)->seconds();
        }
        $this->assertCount(2, glob($this->directory.'/backups/*.cbk'));
        $this->expectException(RuntimeException::class);
        app(BackupService::class)->create(public_path());
    }

    public function test_commands_require_explicit_restore_and_resume_confirmation(): void
    {
        $this->artisan('ops:restore', ['artifact' => 'not-used'])->assertExitCode(2);
        $this->artisan('ops:pause')->assertSuccessful();
        $this->artisan('ops:resume')->assertExitCode(2);
        $this->artisan('ops:resume', ['--force' => true])->assertSuccessful();
        $this->assertFalse(app(MaintenanceGuard::class)->isPaused());
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $child = $path.'/'.$name;
            if (is_dir($child) && ! is_link($child)) {
                $this->removeDirectory($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
