<?php

return [
    'token' => env('TELEGRAM_BOT_TOKEN'),
    'api_url' => 'https://api.telegram.org',
    'staging_chat_id' => env('TELEGRAM_STAGING_CHAT_ID'),
    'poll_timeout' => (int) env('TELEGRAM_POLL_TIMEOUT', 25),
    'connect_timeout' => (int) env('TELEGRAM_CONNECT_TIMEOUT', 15),
    'request_timeout' => (int) env('TELEGRAM_REQUEST_TIMEOUT', 15),
    'lease_seconds' => (int) env('TELEGRAM_LEASE_SECONDS', 90),
    'max_attempts' => (int) env('TELEGRAM_MAX_ATTEMPTS', 12),
    'global_messages_per_second' => (int) env('TELEGRAM_GLOBAL_MESSAGES_PER_SECOND', 25),
    'allow_shared_destinations' => false,
];
