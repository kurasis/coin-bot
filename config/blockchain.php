<?php

$provider = static fn (string $prefix, bool $tron = false): array => [
    'rpc_url' => env($prefix.'_RPC_URL'),
    'native_url' => $tron ? env($prefix.'_NATIVE_URL') : null,
    'headers' => $tron && env($prefix.'_API_KEY') ? ['TRON-PRO-API-KEY' => env($prefix.'_API_KEY')] : [],
    'timeout' => (int) env($prefix.'_TIMEOUT', 20),
    'connect_timeout' => (int) env($prefix.'_CONNECT_TIMEOUT', 15),
    'attempts' => (int) env($prefix.'_ATTEMPTS', 3),
    'backoff_ms' => (int) env($prefix.'_BACKOFF_MS', 125),
    'max_backoff_ms' => (int) env($prefix.'_MAX_BACKOFF_MS', 1000),
    'max_block_range' => (int) env($prefix.'_MAX_BLOCK_RANGE', 5000),
    'max_topic_addresses' => (int) env($prefix.'_MAX_TOPIC_ADDRESSES', 100),
    'max_results' => (int) env($prefix.'_MAX_RESULTS', 1000),
    // Null means retention has not been certified for this actual provider account.
    'history_start_block' => env($prefix.'_HISTORY_START_BLOCK'),
];

return [
    'chains' => [
        'ethereum' => ['providers' => [
            'primary' => $provider('ETHEREUM_PRIMARY'),
            'secondary' => $provider('ETHEREUM_SECONDARY'),
        ]],
        'tron' => ['providers' => [
            'primary' => $provider('TRON_PRIMARY', true),
            'secondary' => $provider('TRON_SECONDARY', true),
        ]],
    ],
];
