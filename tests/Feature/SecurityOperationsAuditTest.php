<?php

namespace Tests\Feature;

use App\Access\AccessService;
use App\Operations\BackupService;
use App\Operations\MaintenanceGuard;
use App\Wallets\WalletService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SecurityOperationsAuditTest extends TestCase
{
    private string $directory;

    private int $firstUser;

    private int $secondUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/coin-bot-security-operations-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        touch($this->directory.'/database.sqlite');
        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'database.connections.sqlite.database' => $this->directory.'/database.sqlite',
            'operations.state_directory' => $this->directory.'/state',
            'operations.backup_directory' => $this->directory.'/backups',
        ]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        $access = app(AccessService::class);
        $this->firstUser = $access->activate($access->issue()->token, '801000001')->userId;
        $this->secondUser = $access->activate($access->issue()->token, '801000002')->userId;
        app(WalletService::class)->add($this->firstUser, 'ethereum', '0x'.str_repeat('1', 40), 'Private Alice audit label', 100);
        app(WalletService::class)->add($this->secondUser, 'ethereum', '0x'.str_repeat('2', 40), 'Private Bob audit label', 100);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        DB::purge('operations_restore');
        $this->removeDirectory($this->directory);
        parent::tearDown();
    }

    #[DataProvider('forgedChunkLengths')]
    public function test_forged_backup_chunk_lengths_are_rejected_without_partial_restore(int $length): void
    {
        $artifact = app(BackupService::class)->create();
        $bytes = file_get_contents($artifact);
        $headerLength = strlen("COINBOT-BACKUP-1\n") + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
        // The large claim is just four bytes; this test never allocates that size.
        file_put_contents($artifact, substr($bytes, 0, $headerLength).pack('N', $length));
        $this->assertRejectedRestorePreservesState($artifact);
    }

    public static function forgedChunkLengths(): array
    {
        return [[0], [16], [65536 + 18], [4294967295]];
    }

    public function test_trailing_data_after_an_authenticated_final_record_is_rejected(): void
    {
        $artifact = app(BackupService::class)->create();
        file_put_contents($artifact, "\n<script>private-appended-payload</script>", FILE_APPEND);
        $this->assertRejectedRestorePreservesState($artifact);
    }

    public function test_reordering_complete_encrypted_records_is_rejected(): void
    {
        $artifact = app(BackupService::class)->create();
        $bytes = file_get_contents($artifact);
        $offset = strlen("COINBOT-BACKUP-1\n") + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
        $firstLength = 4 + unpack('Nlength', substr($bytes, $offset, 4))['length'];
        $secondOffset = $offset + $firstLength;
        $secondLength = 4 + unpack('Nlength', substr($bytes, $secondOffset, 4))['length'];
        file_put_contents($artifact, substr($bytes, 0, $offset)
            .substr($bytes, $secondOffset, $secondLength)
            .substr($bytes, $offset, $firstLength)
            .substr($bytes, $secondOffset + $secondLength));
        $this->assertRejectedRestorePreservesState($artifact);
    }

    public function test_backup_directory_alias_cannot_bypass_public_directory_protection(): void
    {
        $public = $this->directory.'/public';
        mkdir($public, 0755);
        $this->app->usePublicPath($public);
        symlink($public, $this->directory.'/public-alias');
        $before = $this->databaseSnapshot();
        try {
            app(BackupService::class)->create($this->directory.'/public-alias');
            $this->fail('A symlink into the public directory must not expose backups.');
        } catch (RuntimeException) {
            $this->assertSame([], glob($public.'/*'));
            $this->assertSame($before, $this->databaseSnapshot());
        }
        $protected = app(BackupService::class)->create();
        $this->assertFileExists($protected);
        $this->assertSame(0600, fileperms($protected) & 0777);
        $this->assertSame([], glob($this->directory.'/.sqlite-snapshot-*'));
    }

    public function test_symlink_restore_input_is_rejected_and_regular_backup_restores_both_users_safely(): void
    {
        $artifact = app(BackupService::class)->create();
        $alias = $this->directory.'/backup-alias.cbk';
        symlink($artifact, $alias);
        $this->assertRejectedRestorePreservesState($alias);

        app(BackupService::class)->restore($artifact, workersStopped: true, operatorId: 'security-audit');
        $this->assertSame(2, DB::table('users')->count());
        $this->assertSame('Private Alice audit label', DB::table('user_wallets')->where('user_id', $this->firstUser)->value('label'));
        $this->assertSame('Private Bob audit label', DB::table('user_wallets')->where('user_id', $this->secondUser)->value('label'));
        $this->assertNull(app(AccessService::class)->activeGrant($this->firstUser));
        $this->assertNull(app(AccessService::class)->activeGrant($this->secondUser));
        $this->assertSame(0, DB::table('access_keys')->whereNull('revoked_at')->count());
        $this->assertTrue(app(MaintenanceGuard::class)->isPaused());
        $this->assertSame(0600, fileperms($this->directory.'/database.sqlite') & 0777);
        Http::assertNothingSent();
    }

    public function test_actual_private_wallet_labels_are_encrypted_in_the_backup(): void
    {
        $this->assertSame(2, DB::table('user_wallets')->whereIn('label', [
            'Private Alice audit label', 'Private Bob audit label',
        ])->count());
        $artifact = app(BackupService::class)->create();
        $bytes = file_get_contents($artifact);
        $this->assertStringNotContainsString('Private Alice audit label', $bytes);
        $this->assertStringNotContainsString('Private Bob audit label', $bytes);
        $this->assertSame([], glob($this->directory.'/.sqlite-snapshot-*'));
    }

    public function test_retention_keeps_the_completed_backup_when_the_clock_moves_backwards(): void
    {
        $this->freezeTime();
        config(['operations.backup_retention' => 1]);
        $oldArtifact = app(BackupService::class)->create();
        $this->travel(-2)->seconds();
        $newArtifact = app(BackupService::class)->create();

        $this->assertFileExists($newArtifact);
        $this->assertFileDoesNotExist($oldArtifact);
        $this->assertSame($newArtifact, app(BackupService::class)->lastBackup()['artifact']);
        $this->assertCount(1, glob($this->directory.'/backups/*.cbk'));
    }

    public function test_restore_with_a_missing_application_key_does_not_leave_staging_files(): void
    {
        $artifact = app(BackupService::class)->create();
        config(['app.key' => '']);
        $this->assertRejectedRestorePreservesState($artifact);
    }

    public function test_retention_keeps_each_completed_same_second_backup_and_enforces_the_cap(): void
    {
        $this->freezeTime();
        config(['operations.backup_retention' => 2]);
        for ($index = 0; $index < 4; $index++) {
            $artifact = app(BackupService::class)->create();
            $this->assertFileExists($artifact);
            $this->assertSame($artifact, app(BackupService::class)->lastBackup()['artifact']);
            $this->assertCount(min($index + 1, 2), glob($this->directory.'/backups/*.cbk'));
        }
    }

    public function test_cli_import_handles_shell_metacharacters_as_a_literal_filename(): void
    {
        $marker = 'unexpected-shell-side-effect-'.bin2hex(random_bytes(4));
        $file = $this->directory.'/wallets-$(touch '.$marker.').txt';
        file_put_contents($file, '0x'.str_repeat('3', 40)."\n");
        $oldDirectory = getcwd();
        try {
            chdir($this->directory);
            $status = Artisan::call('wallet:import', [
                'file' => $file, '--user' => (string) $this->firstUser,
                '--chain' => 'ethereum', '--start-block' => '100',
            ]);
            $this->assertSame(0, $status);
            $this->assertStringContainsString('Processed 1 wallet records.', Artisan::output());
            $this->assertFileDoesNotExist($this->directory.'/'.$marker);
            $this->assertSame(2, DB::table('user_wallets')->where('user_id', $this->firstUser)->count());
            $this->assertSame(1, DB::table('user_wallets')->where('user_id', $this->secondUser)->count());
            $this->assertSame(0, DB::table('notification_outbox')->count());
            Http::assertNothingSent();
        } finally {
            chdir($oldDirectory);
        }
    }

    private function assertRejectedRestorePreservesState(string $artifact): void
    {
        app(MaintenanceGuard::class)->pause();
        $before = $this->databaseSnapshot();
        $status = Artisan::call('ops:restore', [
            'artifact' => $artifact, '--force' => true, '--workers-stopped' => true,
        ]);
        $this->assertSame(1, $status);
        $this->assertStringContainsString('Restore failed.', Artisan::output());
        $this->assertStringNotContainsString($artifact, Artisan::output());
        $this->assertStringNotContainsString('Private Alice audit label', Artisan::output());
        $this->assertStringNotContainsString('Private Bob audit label', Artisan::output());
        $this->assertSame($before, $this->databaseSnapshot());
        $this->assertTrue(app(MaintenanceGuard::class)->isPaused());
        $this->assertSame([], glob($this->directory.'/.restore-*'));
        Http::assertNothingSent();
    }

    private function databaseSnapshot(): string
    {
        $data = [];
        foreach (DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $data[$table->name] = DB::table($table->name)->get()->map(fn ($row) => (array) $row)->all();
        }

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function removeDirectory(string $path): void
    {
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
