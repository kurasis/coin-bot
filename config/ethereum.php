<?php

return [
    'early_enabled' => (bool) env('ETHEREUM_EARLY_ENABLED', false),
    'early_interval_seconds' => env('ETHEREUM_EARLY_INTERVAL_SECONDS', 30),
    'confirmations' => (int) env('ETHEREUM_EARLY_CONFIRMATIONS', 6),
    'reorg_window' => (int) env('ETHEREUM_EARLY_REORG_WINDOW', 128),
];
