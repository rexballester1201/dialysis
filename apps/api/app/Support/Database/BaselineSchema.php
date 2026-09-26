<?php

declare(strict_types=1);

namespace App\Support\Database;

/**
 * A connection-agnostic copy of the baseline schema.
 *
 * database/schema/mysql-schema.sql opens with `CREATE DATABASE dialysis` and
 * `USE dialysis;` because it is also executed standalone through the mysql
 * client, which is the path CLAUDE.md documents for the smoke test.
 *
 * Laravel loads the same file by piping it into `mysql --database=<connection>`.
 * That `USE` silently overrides the --database flag, so loading the baseline for
 * dialysis_test would create all 66 tables in the development database instead
 * and the test run would quietly scribble over development data.
 *
 * Rather than edit the validated file -- it is the definition of record and the
 * standalone path depends on those two statements -- we hand the migrator a
 * stripped copy.
 */
final class BaselineSchema
{
    public const SOURCE = 'schema/mysql-schema.sql';

    /** Regenerated whenever the baseline is newer than the stripped copy. */
    public static function portablePath(): string
    {
        $source = database_path(self::SOURCE);
        $target = storage_path('framework/testing/mysql-schema.portable.sql');

        if (! is_dir($directory = dirname($target))) {
            mkdir($directory, 0o755, recursive: true);
        }

        if (! is_file($target) || filemtime($target) < filemtime($source)) {
            file_put_contents($target, self::strip((string) file_get_contents($source)));
        }

        return $target;
    }

    /** Remove only the statements that choose a database. Nothing else. */
    public static function strip(string $sql): string
    {
        $sql = preg_replace('/\bCREATE\s+DATABASE\b.*?;/is', '', $sql) ?? $sql;

        return preg_replace('/\bUSE\s+`?\w+`?\s*;/i', '', $sql) ?? $sql;
    }
}
