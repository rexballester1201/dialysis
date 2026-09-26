<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Backup status file
    |--------------------------------------------------------------------------
    |
    | Path to a file whose mtime is the last SUCCESSFUL backup. The backup job
    | touches it only after the dump has been written and verified, so its
    | timestamp cannot drift away from reality the way a self-reported "backup
    | ran" flag does.
    |
    | Left null until a real backup job exists. GET /api/v1/health then reports
    | the backup check as not_configured rather than pretending it passed.
    |
    */

    'status_path' => env('BACKUP_STATUS_PATH'),

];
