<?php

return [
    'key_lifetime_hours' => (int) env('ACCESS_KEY_LIFETIME_HOURS', 24),
    'activation_attempts' => (int) env('ACCESS_ACTIVATION_ATTEMPTS', 10),
    'activation_decay_seconds' => (int) env('ACCESS_ACTIVATION_DECAY_SECONDS', 300),
];
