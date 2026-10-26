<?php

$provider = static fn (string $prefix, bool $tron = false): array => [
    'rpc_url' => env($prefix.'_RPC_URL'),
    'native_url' => $tron ? env($prefix.'_NATIVE_URL') : null,
    'headers' => $tron && env($prefix.'_API_KEY') ? ['TRON-PRO-API-KEY' => env($prefix.'_API_KEY')] : [],
    'timeout' => (int) env($prefix.'_TIMEOUT', 20),
    'connect_timeout' => (int) env($prefix.'_CONNECT_TIMEOUT', 15),
    'attempts' => 3,
    'backoff_ms' => 125,
    'max_backoff_ms' => 1000,
    'max_block_range' => (int) env($prefix.'_MAX_BLOCK_RANGE', 5000),
    'max_topic_addresses' => (int) env($prefix.'_MAX_TOPIC_ADDRESSES', 100),
    'max_results' => (int) env($prefix.'_MAX_RESULTS', 1000),
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
