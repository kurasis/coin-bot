<?php

return [
    'early_enabled' => (bool) env('ETHEREUM_EARLY_ENABLED', false),
    'confirmations' => (int) env('ETHEREUM_EARLY_CONFIRMATIONS', 6),
    'reorg_window' => (int) env('ETHEREUM_EARLY_REORG_WINDOW', 128),
];
