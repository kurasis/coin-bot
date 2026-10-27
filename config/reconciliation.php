<?php

return [
    'circuit_base_seconds' => (int) env('RPC_CIRCUIT_BASE_SECONDS', 15),
    'circuit_max_seconds' => (int) env('RPC_CIRCUIT_MAX_SECONDS', 300),
    'recheck_after_seconds' => (int) env('RECONCILIATION_RECHECK_SECONDS', 43200),
    'overlap_blocks' => [
        'ethereum' => (int) env('RECONCILIATION_ETHEREUM_OVERLAP_BLOCKS', 7200),
        'tron' => (int) env('RECONCILIATION_TRON_OVERLAP_BLOCKS', 28800),
    ],
];
