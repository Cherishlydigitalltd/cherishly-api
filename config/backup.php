<?php

// Nightly database backups (see App\Console\Commands\BackupDatabase).
return [
    // How many days of backup files to keep in storage/app/backups.
    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),

    // Full path to pg_dump if it isn't on the PATH. Leave empty to search.
    'pg_dump' => env('BACKUP_PG_DUMP'),
];
