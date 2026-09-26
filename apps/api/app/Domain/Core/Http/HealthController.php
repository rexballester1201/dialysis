<?php

declare(strict_types=1);

namespace App\Domain\Core\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Unauthenticated liveness/readiness probe.
 *
 * It reports what it actually observed. A check that cannot be performed says
 * so ("not_configured") rather than reporting a pass -- a green health endpoint
 * that is green because nothing was checked is worse than no endpoint.
 */
final class HealthController extends Controller
{
    /** What database/schema/mysql-schema.sql is expected to define. */
    private const EXPECTED_TABLES = 66;

    private const EXPECTED_VIEWS = 12;

    private const EXPECTED_TRIGGERS = 6;

    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->database(),
            'redis' => $this->redis(),
            'migrations' => $this->migrations(),
            'schema' => $this->schema(),
            'backup' => $this->backup(),
        ];

        $failed = collect($checks)->contains(fn (array $check): bool => $check['status'] === 'fail');

        return response()->json([
            'status' => $failed ? 'fail' : 'ok',
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
        ], $failed ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_OK);
    }

    /**
     * @return array<string, mixed>
     */
    private function database(): array
    {
        try {
            $row = DB::selectOne('SELECT VERSION() AS version, DATABASE() AS db');

            return [
                'status' => 'ok',
                'version' => $row->version ?? null,
                'database' => $row->db ?? null,
            ];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function redis(): array
    {
        $usesRedis = in_array('redis', [
            config('queue.default'),
            config('cache.default'),
            config('session.driver'),
        ], true);

        if (! $usesRedis) {
            return [
                'status' => 'not_configured',
                'detail' => 'No queue, cache or session driver is set to redis.',
            ];
        }

        try {
            Redis::connection()->ping();

            return ['status' => 'ok'];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function migrations(): array
    {
        try {
            $ran = DB::table('migrations')->count();

            $onDisk = collect(File::files(database_path('migrations')))
                ->filter(fn ($file): bool => $file->getExtension() === 'php')
                ->count();

            return [
                'status' => $ran >= $onDisk ? 'ok' : 'fail',
                'ran' => $ran,
                'on_disk' => $onDisk,
                'pending' => max(0, $onDisk - $ran),
            ];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        try {
            $database = DB::connection()->getDatabaseName();

            $tables = (int) DB::table('information_schema.tables')
                ->where('table_schema', $database)
                ->where('table_type', 'BASE TABLE')
                ->count();

            $views = (int) DB::table('information_schema.views')
                ->where('table_schema', $database)
                ->count();

            $triggers = (int) DB::table('information_schema.triggers')
                ->where('trigger_schema', $database)
                ->count();

            // The baseline owns 66 tables; Laravel's `migrations` ledger and any
            // post-baseline migration tables sit on top of it, so compare on the
            // floor rather than on equality.
            $ok = $tables >= self::EXPECTED_TABLES
                && $views === self::EXPECTED_VIEWS
                && $triggers === self::EXPECTED_TRIGGERS;

            return [
                'status' => $ok ? 'ok' : 'fail',
                'tables' => $tables,
                'views' => $views,
                'triggers' => $triggers,
                'expected' => [
                    'tables_at_least' => self::EXPECTED_TABLES,
                    'views' => self::EXPECTED_VIEWS,
                    'triggers' => self::EXPECTED_TRIGGERS,
                ],
            ];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'error' => $e->getMessage()];
        }
    }

    /**
     * Last successful backup.
     *
     * Phase 0 has no backup job, and inventing a timestamp here would make an
     * unbacked-up clinical database look protected. It reports not_configured
     * until something real writes BACKUP_STATUS_PATH.
     *
     * @return array<string, mixed>
     */
    private function backup(): array
    {
        $path = config('backup.status_path');

        if (! is_string($path) || $path === '') {
            return [
                'status' => 'not_configured',
                'detail' => 'No backup job is configured yet; set BACKUP_STATUS_PATH once one exists.',
                'last_successful_at' => null,
            ];
        }

        if (! File::exists($path)) {
            return [
                'status' => 'fail',
                'detail' => 'Backup status file not found.',
                'last_successful_at' => null,
            ];
        }

        return [
            'status' => 'ok',
            'last_successful_at' => date(DATE_ATOM, (int) File::lastModified($path)),
        ];
    }
}
