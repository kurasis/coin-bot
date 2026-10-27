<?php

use App\Operations\NativeBackupPolicy;
use Illuminate\Contracts\Console\Kernel;

// Root configuration writes never boot Laravel or execute writable app caches.
// The mode query is run separately as the unprivileged application user.
try {
    $root = dirname(__DIR__);
    require $root.'/vendor/autoload.php';
    $mode = $argv[1] ?? '';
    if ($mode === 'mode') {
        $app = require $root.'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        echo app(NativeBackupPolicy::class)->mode().PHP_EOL;
        exit(0);
    }
    if (! in_array($mode, ['local', 'offhost'], true)) {
        throw new RuntimeException('Invalid backup mode.');
    }
    $path = $root.'/.env';
    if (! is_file($path) || is_link($path)) {
        throw new RuntimeException('A regular installed configuration is required.');
    }
    $contents = file_get_contents($path);
    $owner = fileowner($path);
    $group = filegroup($path);
    if ($contents === false || $owner === false || $group === false) {
        throw new RuntimeException('Cannot read installed configuration metadata.');
    }
    $values = [
        'OPERATIONS_BACKUP_MODE' => $mode,
        'OPERATIONS_BACKUP_DIRECTORY' => $mode === 'local'
            ? NativeBackupPolicy::LOCAL_DIRECTORY : NativeBackupPolicy::OFFHOST_DIRECTORY,
        'OPERATIONS_REQUIRE_BACKUP' => 'true',
    ];
    foreach ($values as $key => $value) {
        $contents = preg_replace('/^[\t ]*(?:export[\t ]+)?'.preg_quote($key, '/').'[\t ]*=.*$/m', $key.'='.$value, $contents, count: $count);
        if ($contents === null || $count > 1) {
            throw new RuntimeException('Configuration contains duplicate backup settings.');
        }
        if ($count === 0) {
            $contents = rtrim($contents, "\r\n").PHP_EOL.$key.'='.$value.PHP_EOL;
        }
    }
    Dotenv\Dotenv::parse($contents);
    $temporary = tempnam($root, '.backup-mode-');
    if ($temporary === false) {
        throw new RuntimeException('Cannot stage private backup configuration.');
    }
    try {
        if (! chmod($temporary, 0600) || file_put_contents($temporary, $contents) !== strlen($contents)
            || ! chown($temporary, $owner) || ! chgrp($temporary, $group) || ! rename($temporary, $path)) {
            throw new RuntimeException('Cannot publish private backup configuration.');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
    echo 'Native backup configuration updated. Application key and other settings preserved.'.PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, 'Backup configuration failed. Check the installed files, permissions and configuration syntax; secret values are omitted.'.PHP_EOL);
    exit(1);
}
