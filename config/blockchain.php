<?php

$provider = static function (string $prefix, bool $tron = false): array {
    $historyStart = env($prefix.'_HISTORY_START_BLOCK');

    $optional = static function (string $suffix) use ($prefix): mixed {
        $value = env($prefix.$suffix);

        return $value === '' ? null : $value;
    };
    $host = strtolower((string) parse_url((string) env($prefix.'_RPC_URL', ''), PHP_URL_HOST));
    $span = $host === 'alchemy.com' || str_ends_with($host, '.alchemy.com') ? 10
        : (($host === 'quiknode.pro' || str_ends_with($host, '.quiknode.pro')) ? 5 : 5000);

    return [
        'rpc_url' => env($prefix.'_RPC_URL'),
        'native_url' => $tron ? env($prefix.'_NATIVE_URL') : null,
        'headers' => $tron && env($prefix.'_API_KEY') ? ['TRON-PRO-API-KEY' => env($prefix.'_API_KEY')] : [],
        'timeout' => (int) env($prefix.'_TIMEOUT', 20),
        'connect_timeout' => (int) env($prefix.'_CONNECT_TIMEOUT', 15),
        'attempts' => (int) env($prefix.'_ATTEMPTS', 3),
        'backoff_ms' => (int) env($prefix.'_BACKOFF_MS', 125),
        'max_backoff_ms' => (int) env($prefix.'_MAX_BACKOFF_MS', 1000),
        'max_block_range' => (int) env($prefix.'_MAX_BLOCK_RANGE', $span),
        'max_topic_addresses' => (int) env($prefix.'_MAX_TOPIC_ADDRESSES', 100),
        'max_results' => (int) env($prefix.'_MAX_RESULTS', 1000),
        'max_response_bytes' => (int) env($prefix.'_MAX_RESPONSE_BYTES', 8 * 1024 * 1024),
        'usage_profile' => $optional('_USAGE_PROFILE') ?? 'auto',
        'rate_group' => $optional('_RATE_GROUP'),
        'rate_per_second' => $optional('_RATE_PER_SECOND'),
        'daily_target' => $optional('_DAILY_TARGET'),
        // Null means retention has not been certified for this actual provider account.
        'history_start_block' => $historyStart === null || (is_string($historyStart) && trim($historyStart) === '') ? null : $historyStart,
    ];
};

return [
    'chains' => [
        'ethereum' => ['providers' => [
            'primary' => $provider('ETHEREUM_PRIMARY'),
            'secondary' => $provider('ETHEREUM_SECONDARY'),
        ]],
        'tron' => ['scan_min_interval_seconds' => (int) env('TRON_SCAN_MIN_INTERVAL_SECONDS', 15), 'providers' => [
            'primary' => $provider('TRON_PRIMARY', true),
            'secondary' => $provider('TRON_SECONDARY', true),
        ]],
    ],
];
