<?php

// Privileged configuration writes do not boot Laravel or writable app caches.
try {
    if (($argv[1] ?? '') !== 'enable' || count($argv) !== 2) {
        throw new RuntimeException('Use enable.');
    }
    $root = dirname(__DIR__);
    require $root.'/vendor/autoload.php';
    $path = $root.'/.env';
    if (! is_file($path) || is_link($path)) {
        throw new RuntimeException('A regular installed configuration is required.');
    }
    $contents = file_get_contents($path);
    $owner = fileowner($path);
    $group = filegroup($path);
    if ($contents === false || $owner === false || $group === false) {
        throw new RuntimeException('Cannot read private configuration metadata.');
    }
    $parsed = Dotenv\Dotenv::parse($contents);
    $workers = $parsed['OPERATIONS_REQUIRED_WORKERS'] ?? '';
    if (! is_string($workers)) {
        throw new RuntimeException('Invalid worker health configuration.');
    }
    $workers = array_values(array_unique(array_filter(array_map('trim', explode(',', $workers)))));
    if (! in_array('scan-ethereum-early', $workers, true)) {
        $workers[] = 'scan-ethereum-early';
    }
    $values = [
        'ETHEREUM_EARLY_ENABLED' => 'true',
        'ETHEREUM_EARLY_CONFIRMATIONS' => '6',
        'ETHEREUM_EARLY_REORG_WINDOW' => '128',
        'OPERATIONS_ALERT_USER_ID' => '1',
        'OPERATIONS_ALERT_FAILURE_THRESHOLD' => '3',
        'OPERATIONS_ALERT_MIN_OUTAGE_SECONDS' => '30',
        'OPERATIONS_ALERT_CHECK_INTERVAL_SECONDS' => '15',
        'OPERATIONS_REQUIRED_WORKERS' => implode(',', $workers),
    ];
    foreach ($values as $key => $value) {
        $contents = preg_replace('/^[\t ]*(?:export[\t ]+)?'.preg_quote($key, '/').'[\t ]*=.*$/m', $key.'='.$value, $contents, count: $count);
        if ($contents === null || $count > 1) {
            throw new RuntimeException('Duplicate monitoring configuration.');
        }
        if ($count === 0) {
            $contents = rtrim($contents, "\r\n").PHP_EOL.$key.'='.$value.PHP_EOL;
        }
    }
    $after = Dotenv\Dotenv::parse($contents);
    foreach ($parsed as $key => $value) {
        if (! array_key_exists($key, $values) && (! array_key_exists($key, $after) || $after[$key] !== $value)) {
            throw new RuntimeException('An unrelated configuration value would change.');
        }
    }
    $temporary = tempnam($root, '.monitoring-config-');
    if ($temporary === false) {
        throw new RuntimeException('Cannot stage private monitoring configuration.');
    }
    try {
        if (! chmod($temporary, 0600) || file_put_contents($temporary, $contents) !== strlen($contents)
            || ! chown($temporary, $owner) || ! chgrp($temporary, $group) || ! rename($temporary, $path)) {
            throw new RuntimeException('Cannot publish private monitoring configuration.');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
    echo 'Early Ethereum monitoring and three-check operator alerts enabled. Existing credentials, backup policy and other settings preserved.'.PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, 'Monitoring configuration failed. Check file permissions and syntax; secret values are omitted.'.PHP_EOL);
    exit(1);
}
