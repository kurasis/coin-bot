<?php

return [
    'backup_mode' => env('OPERATIONS_BACKUP_MODE', 'offhost'),
    'alert_user_id' => env('OPERATIONS_ALERT_USER_ID', 1),
    'alert_failure_threshold' => env('OPERATIONS_ALERT_FAILURE_THRESHOLD', 3),
    'alert_min_outage_seconds' => env('OPERATIONS_ALERT_MIN_OUTAGE_SECONDS', 30),
    'alert_check_interval_seconds' => env('OPERATIONS_ALERT_CHECK_INTERVAL_SECONDS', 15),
    'state_directory' => env('OPERATIONS_STATE_DIRECTORY', storage_path('app/operations')),
    'backup_directory' => env('OPERATIONS_BACKUP_DIRECTORY', storage_path('app/backups')),
    'backup_retention' => (int) env('OPERATIONS_BACKUP_RETENTION', 1440),
    'max_backup_age_seconds' => (int) env('OPERATIONS_MAX_BACKUP_AGE', 180),
    'max_cursor_age_seconds' => (int) env('OPERATIONS_MAX_CURSOR_AGE', 1200),
    'max_unreconciled_age_seconds' => (int) env('OPERATIONS_MAX_UNRECONCILED_AGE', 1800),
    'max_outbox_age_seconds' => (int) env('OPERATIONS_MAX_OUTBOX_AGE', 300),
    'max_heartbeat_age_seconds' => (int) env('OPERATIONS_MAX_HEARTBEAT_AGE', 120),
    'required_workers' => array_filter(explode(',', (string) env('OPERATIONS_REQUIRED_WORKERS', ''))),
    'require_backup' => (bool) env('OPERATIONS_REQUIRE_BACKUP', true),
    'minimum_free_bytes' => (int) env('OPERATIONS_MINIMUM_FREE_BYTES', 268435456),
    'max_wal_bytes' => (int) env('OPERATIONS_MAX_WAL_BYTES', 134217728),
];
