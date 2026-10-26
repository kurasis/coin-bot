<?php

namespace App\Operations;

use App\Access\AccessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SQLite3;
use Throwable;

class BackupService
{
    private const MAGIC = "COINBOT-BACKUP-1\n";

    private const CHUNK_BYTES = 65536;

    public function __construct(private readonly MaintenanceGuard $maintenance = new MaintenanceGuard) {}

    public function create(?string $directory = null): string
    {
        $source = $this->databasePath();
        $directory = $this->safeDirectory($directory ?? (string) config('operations.backup_directory'));
        $key = $this->encryptionKey();
        $snapshot = tempnam(dirname($source), '.sqlite-snapshot-');
        if ($snapshot === false) {
            throw new RuntimeException('Cannot allocate consistent backup snapshot.');
        }
        chmod($snapshot, 0600);
        $artifact = $directory.'/coin-bot-'.now()->utc()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.cbk';
        $temporary = $artifact.'.partial';
        try {
            $this->snapshot($source, $snapshot);
            $this->encryptFile($snapshot, $temporary, $key);
            if (! rename($temporary, $artifact)) {
                throw new RuntimeException('Cannot publish encrypted backup artifact.');
            }
            chmod($artifact, 0600);
            $this->recordBackup($artifact);
            $this->prune($directory);

            return $artifact;
        } finally {
            sodium_memzero($key);
            foreach ([$snapshot, $snapshot.'-wal', $snapshot.'-shm', $temporary] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function restore(string $artifact, bool $workersStopped, ?string $operatorId = null): void
    {
        if (! $workersStopped || ! $this->maintenance->isPaused()) {
            throw new RuntimeException('Pause operations and stop/fence every writer before restore.');
        }
        $target = $this->databasePath();
        if (! is_file($artifact) || is_link($artifact)) {
            throw new RuntimeException('An existing regular backup artifact is required.');
        }
        $temporary = tempnam(dirname($target), '.restore-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot allocate restore staging file.');
        }
        chmod($temporary, 0600);
        $key = $this->encryptionKey();
        try {
            $this->decryptFile($artifact, $temporary, $key);
            $this->assertIntegrity($temporary);
            $this->invalidateStagedAccess($temporary, $operatorId);
            $this->assertIntegrity($temporary);
            DB::purge();
            $this->checkpointStoppedDatabase($target);
            foreach ([$target.'-wal', $target.'-shm'] as $path) {
                if (is_file($path) && ! unlink($path)) {
                    throw new RuntimeException('Cannot remove stopped database sidecar.');
                }
            }
            if (! rename($temporary, $target)) {
                throw new RuntimeException('Cannot atomically install the validated restore.');
            }
            chmod($target, 0600);
            $this->maintenance->pause('Database restored. All restored access invalidated; operator verification and fresh enrollment required.');
        } catch (Throwable $exception) {
            throw new RuntimeException('Backup restore failed; operations remain paused.', 0, $exception);
        } finally {
            sodium_memzero($key);
            DB::purge('operations_restore');
            foreach ([$temporary, $temporary.'-wal', $temporary.'-shm'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function lastBackup(): ?array
    {
        $path = rtrim((string) config('operations.state_directory'), '/').'/backup.json';
        if (! is_file($path)) {
            return null;
        }
        $record = json_decode((string) file_get_contents($path), true);

        return is_array($record) ? $record : null;
    }

    private function databasePath(): string
    {
        $connection = DB::connection();
        $path = $connection->getDatabaseName();
        if ($connection->getDriverName() !== 'sqlite' || ! is_string($path) || $path === ':memory:' || ! is_file($path) || is_link($path)) {
            throw new RuntimeException('Operations require an existing regular, file-backed SQLite database.');
        }

        return realpath($path) ?: throw new RuntimeException('Cannot resolve database path.');
    }

    private function encryptionKey(): string
    {
        $value = (string) config('app.key');
        $raw = str_starts_with($value, 'base64:') ? base64_decode(substr($value, 7), true) : $value;
        if (! is_string($raw) || strlen($raw) < 32) {
            throw new RuntimeException('A securely configured application key is required for encrypted backups.');
        }

        return hash_hkdf('sha256', $raw, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, 'coin-bot:sqlite-backup:v1');
    }

    private function safeDirectory(string $path): string
    {
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('Cannot create protected backup directory.');
        }
        $resolved = realpath($path);
        $public = realpath(public_path());
        if ($resolved === false || ($public !== false && ($resolved === $public || str_starts_with($resolved, $public.'/')))) {
            throw new RuntimeException('Backup artifacts must remain outside the public directory.');
        }

        return $resolved;
    }

    private function snapshot(string $source, string $destination): void
    {
        $reader = new SQLite3($source, SQLITE3_OPEN_READONLY);
        $writer = new SQLite3($destination, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $reader->enableExceptions(true);
        $writer->enableExceptions(true);
        try {
            $reader->busyTimeout(5000);
            $writer->busyTimeout(5000);
            if (! $reader->backup($writer)) {
                throw new RuntimeException('SQLite online backup did not complete.');
            }
        } finally {
            $writer->close();
            $reader->close();
        }
        $this->assertIntegrity($destination);
    }

    private function assertIntegrity(string $path): void
    {
        $database = new SQLite3($path, SQLITE3_OPEN_READONLY);
        try {
            if ($database->querySingle('PRAGMA integrity_check') !== 'ok'
                || $database->querySingle('PRAGMA foreign_key_check') !== null) {
                throw new RuntimeException('Backup database failed integrity verification.');
            }
        } finally {
            $database->close();
        }
    }

    private function checkpointStoppedDatabase(string $path): void
    {
        $database = new SQLite3($path, SQLITE3_OPEN_READWRITE);
        try {
            $database->busyTimeout(5000);
            $result = $database->querySingle('PRAGMA wal_checkpoint(TRUNCATE)', true);
            if (! is_array($result) || (int) (array_values($result)[0] ?? 1) !== 0) {
                throw new RuntimeException('Existing database has active readers or writers; stop them before restore.');
            }
        } finally {
            $database->close();
        }
    }

    private function encryptFile(string $source, string $destination, string $key): void
    {
        $reader = fopen($source, 'rb');
        $writer = fopen($destination, 'xb');
        if ($reader === false || $writer === false) {
            throw new RuntimeException('Cannot open encrypted backup streams.');
        }
        chmod($destination, 0600);
        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            $this->writeAll($writer, self::MAGIC.$header);
            while (! feof($reader)) {
                $chunk = fread($reader, self::CHUNK_BYTES);
                if ($chunk === false) {
                    throw new RuntimeException('Cannot read backup snapshot.');
                }
                if ($chunk !== '') {
                    $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk);
                    $this->writeAll($writer, pack('N', strlen($ciphertext)).$ciphertext);
                }
            }
            $final = sodium_crypto_secretstream_xchacha20poly1305_push($state, '', '', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
            $this->writeAll($writer, pack('N', strlen($final)).$final);
            if (! fflush($writer) || ! fsync($writer)) {
                throw new RuntimeException('Cannot synchronize encrypted backup artifact.');
            }
        } finally {
            fclose($writer);
            fclose($reader);
        }
    }

    private function decryptFile(string $source, string $destination, string $key): void
    {
        $reader = fopen($source, 'rb');
        $writer = fopen($destination, 'wb');
        if ($reader === false || $writer === false) {
            throw new RuntimeException('Cannot open backup restore streams.');
        }
        try {
            if ($this->readExactly($reader, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new RuntimeException('Unsupported encrypted backup format.');
            }
            $header = $this->readExactly($reader, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            while (true) {
                $length = unpack('Nlength', $this->readExactly($reader, 4))['length'];
                if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $length > self::CHUNK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                    throw new RuntimeException('Invalid backup chunk length.');
                }
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $this->readExactly($reader, $length));
                if ($result === false) {
                    throw new RuntimeException('Backup authentication failed.');
                }
                [$plaintext, $tag] = $result;
                $this->writeAll($writer, $plaintext);
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    if (fread($reader, 1) !== '') {
                        throw new RuntimeException('Unexpected trailing backup data.');
                    }
                    break;
                }
            }
            fflush($writer);
            fsync($writer);
        } finally {
            fclose($writer);
            fclose($reader);
        }
    }

    private function invalidateStagedAccess(string $path, ?string $operatorId): void
    {
        $configuration = config('database.connections.sqlite');
        $configuration['url'] = null;
        $configuration['database'] = $path;
        config(['database.connections.operations_restore' => $configuration]);
        DB::purge('operations_restore');
        DB::usingConnection('operations_restore', function () use ($operatorId): void {
            $restoredMigrations = DB::table('migrations')->pluck('migration')->all();
            $knownMigrations = array_map(static fn (string $file): string => pathinfo($file, PATHINFO_FILENAME), glob(database_path('migrations/*.php')) ?: []);
            if (array_diff($restoredMigrations, $knownMigrations) !== []) {
                throw new RuntimeException('Backup schema is newer than this application; use compatible application code.');
            }
            if (Artisan::call('migrate', ['--database' => 'operations_restore', '--force' => true]) !== 0) {
                throw new RuntimeException('Cannot migrate staged backup with the current application.');
            }
            app(AccessService::class)->invalidateRestored($operatorId);
            DB::table('telegram_replies')->whereNotIn('status', ['sent', 'cancelled'])->update([
                'status' => 'cancelled', 'lease_token' => null, 'lease_expires_at' => null,
                'last_error' => 'Reply invalidated after restore.', 'updated_at' => now(),
            ]);
            DB::statement('PRAGMA wal_checkpoint(TRUNCATE)');
        });
        DB::purge('operations_restore');
    }

    private function recordBackup(string $artifact): void
    {
        $directory = rtrim((string) config('operations.state_directory'), '/');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create operational state directory.');
        }
        $temporary = tempnam($directory, '.backup-status-');
        if ($temporary === false || file_put_contents($temporary, json_encode([
            'completed_at' => now()->toIso8601String(), 'artifact' => $artifact,
            'bytes' => filesize($artifact), 'sha256' => hash_file('sha256', $artifact),
        ], JSON_THROW_ON_ERROR)) === false || ! rename($temporary, $directory.'/backup.json')) {
            throw new RuntimeException('Cannot record completed backup status.');
        }
        chmod($directory.'/backup.json', 0600);
    }

    private function prune(string $directory): void
    {
        $files = glob($directory.'/coin-bot-*.cbk') ?: [];
        rsort($files, SORT_STRING);
        $retained = max(1, (int) config('operations.backup_retention', 1440));
        foreach (array_slice($files, $retained) as $file) {
            if (is_file($file) && ! is_link($file)) {
                unlink($file);
            }
        }
    }

    /** @param resource $stream */
    private function writeAll($stream, string $bytes): void
    {
        while ($bytes !== '') {
            $written = fwrite($stream, $bytes);
            if ($written === false || $written === 0) {
                throw new RuntimeException('Cannot write backup stream.');
            }
            $bytes = substr($bytes, $written);
        }
    }

    /** @param resource $stream */
    private function readExactly($stream, int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $chunk = fread($stream, $length - strlen($bytes));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Encrypted backup is incomplete.');
            }
            $bytes .= $chunk;
        }

        return $bytes;
    }
}
