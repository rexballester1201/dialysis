<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Load the reference data in database/mysql-seed.sql.
     *
     * The file is executed verbatim apart from its connection-targeting statements.
     * It opens with `USE dialysis;` because it is also run standalone through the mysql
     * client (see CLAUDE.md), but honouring that here would send the reference data into
     * the development database even when the connection points at dialysis_test. We strip
     * those statements rather than edit the file, so the standalone path keeps working.
     *
     * The inserts are INSERT IGNORE, so re-running this is safe.
     */
    public function run(): void
    {
        $path = database_path('mysql-seed.sql');

        if (! is_file($path)) {
            throw new \RuntimeException("Reference data file not found: {$path}");
        }

        $sql = (string) file_get_contents($path);

        $sql = preg_replace('/\bCREATE\s+DATABASE\b.*?;/is', '', $sql) ?? $sql;
        $sql = preg_replace('/\bUSE\s+`?\w+`?\s*;/i', '', $sql) ?? $sql;

        DB::connection()->unprepared($sql);

        $this->command->info('Loaded reference data from '.basename($path).' into '.DB::connection()->getDatabaseName().'.');
    }
}
