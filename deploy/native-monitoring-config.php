<?php

// Rendering runs as the application user. The native updater publishes its
// bounded output without loading installed PHP or dependencies as root.
try {
    if (($argv[1] ?? '') !== 'render' || count($argv) !== 2
        || ! function_exists('posix_geteuid') || posix_geteuid() === 0) {
        throw new RuntimeException('Use render as the unprivileged application user.');
    }
    $root = '.';
    require $root.'/vendor/autoload.php';
    $path = $root.'/.env';
    if (! is_file($path) || is_link($path)) {
        throw new RuntimeException('A regular installed configuration is required.');
    }
    $contents = file_get_contents($path, length: 1048577);
    $owner = fileowner($path);
    $group = filegroup($path);
    if ($contents === false || strlen($contents) > 1048576 || str_contains($contents, "\0")
        || $owner === false || $group === false) {
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
        'API_USAGE_ENABLED' => 'true',
        'TRON_SCAN_MIN_INTERVAL_SECONDS' => $parsed['TRON_SCAN_MIN_INTERVAL_SECONDS'] ?? '15',
        'RECONCILIATION_RECHECK_SECONDS' => ! isset($parsed['RECONCILIATION_RECHECK_SECONDS']) || $parsed['RECONCILIATION_RECHECK_SECONDS'] === '900' ? '43200' : $parsed['RECONCILIATION_RECHECK_SECONDS'],
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
    echo $contents;
} catch (Throwable) {
    fwrite(STDERR, 'Monitoring configuration failed. Check file permissions and syntax; secret values are omitted.'.PHP_EOL);
    exit(1);
}
