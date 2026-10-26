<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AccessConcurrencyTest extends TestCase
{
    public function test_two_processes_cannot_redeem_the_same_key_for_different_users(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'coin-bot-access-');
        $barrier = sys_get_temp_dir().'/coin-bot-access-barrier-'.Str::random(12);
        $environment = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $database,
            'CACHE_STORE' => 'array',
            'LOG_CHANNEL' => 'stderr',
        ];
        $workers = [];

        try {
            $migration = new Process([PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction'], base_path(), $environment);
            $migration->mustRun();
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $pdo = new PDO('sqlite:'.$database);
            $statement = $pdo->prepare('INSERT INTO access_keys (token_hash, expires_at, created_at, updated_at) VALUES (?, ?, ?, ?)');
            $statement->execute([hash('sha256', $token), now()->addDay()->format('Y-m-d H:i:s'), now()->format('Y-m-d H:i:s'), now()->format('Y-m-d H:i:s')]);
            $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
[$token, $actor, $barrier] = json_decode(stream_get_contents(STDIN), true);
file_put_contents($barrier.'.'.$actor, 'ready');
$deadline = microtime(true) + 10;
while (!file_exists($barrier)) {
    if (microtime(true) > $deadline) { throw new RuntimeException('Barrier timeout.'); }
    usleep(10000);
}
try {
    $app->make(App\Access\AccessService::class)->activate($token, $actor);
    echo 'activated';
} catch (App\Access\AccessDenied) {
    echo 'denied';
}
PHP;
            foreach (['300', '301'] as $actor) {
                $worker = new Process([PHP_BINARY, '-r', $code], base_path(), $environment);
                $worker->setInput(json_encode([$token, $actor, $barrier], JSON_THROW_ON_ERROR));
                $worker->setTimeout(20);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 10;
            while (! file_exists($barrier.'.300') || ! file_exists($barrier.'.301')) {
                if (microtime(true) > $deadline) {
                    $this->fail('Both access workers did not reach the concurrency barrier.');
                }
                usleep(10000);
            }
            file_put_contents($barrier, 'go');
            $outcomes = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $outcomes[] = $worker->getOutput();
            }
            sort($outcomes);
            $this->assertSame(['activated', 'denied'], $outcomes);
            $this->assertSame(1, (int) $pdo->query('SELECT count(*) FROM access_grants')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('SELECT count(*) FROM users')->fetchColumn());
            $pdo = null;
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(0);
                }
            }
            foreach ([$database, $database.'-wal', $database.'-shm', $barrier, $barrier.'.300', $barrier.'.301'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
