<?php

declare(strict_types=1);

namespace App\Domain\Ops\Console;

use App\Domain\Ops\Services\DetectiveControlReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Reads the detective controls and puts the result somewhere a human sees it.
 *
 * Invariants 1, 8 and 9 do not block anything -- they are views that find
 * violations after the fact. Until something reads them on a schedule and
 * escalates, they are documentation, not controls.
 *
 * Exits non-zero when anything is outstanding so a scheduler, a monitoring
 * agent, or a CI smoke run treats it as a failure rather than a log line.
 */
final class CheckDetectiveControls extends Command
{
    protected $signature = 'dialysis:check-controls
                            {--json : Emit machine-readable output for a monitoring agent}';

    protected $description = 'Read the cohort, dialyzer reuse and water detective controls, and report violations';

    public function handle(DetectiveControlReader $reader): int
    {
        $findings = $reader->all();

        $total = count($findings['cohort']) + count($findings['dialyzer']) + count($findings['water']);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'checked_at' => now()->toIso8601String(),
                'total' => $total,
                'findings' => $findings,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return $total === 0 ? self::SUCCESS : self::FAILURE;
        }

        if ($total === 0) {
            $this->info('No outstanding violations across the three detective controls.');

            return self::SUCCESS;
        }

        $this->reportCohort($findings['cohort']);
        $this->reportDialyzer($findings['dialyzer']);
        $this->reportWater($findings['water']);

        // Logged as well as printed: the scheduler's output is nobody's inbox.
        Log::channel(config('logging.default'))->warning('Detective controls found violations', [
            'cohort' => count($findings['cohort']),
            'dialyzer' => count($findings['dialyzer']),
            'water' => count($findings['water']),
        ]);

        $this->newLine();
        $this->error("{$total} outstanding violation(s). These need a human.");

        return self::FAILURE;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function reportCohort(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->error('COHORT (invariant 1) — a patient is seated against their infection-control cohort:');

        $this->table(
            ['Session date', 'MRN', 'Patient', 'Cohort', 'Station', 'Machine', 'Machine cohort'],
            array_map(fn (array $row): array => [
                $row['session_date'],
                $row['mrn'],
                $row['full_name'],
                $row['cohort'],
                $row['station_code'] ?? '—',
                $row['asset_tag'] ?? '—',
                $row['dedicated_cohort'] ?? '—',
            ], $rows),
        );
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function reportDialyzer(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->error('DIALYZER (invariant 8) — must not be issued again:');

        $this->table(
            ['Label', 'MRN', 'Dialyzer', 'Uses', 'Max', 'TCV %', 'Flag'],
            array_map(fn (array $row): array => [
                $row['label_code'],
                $row['mrn'],
                $row['dialyzer'],
                $row['use_count'],
                $row['max_reuse_count'] ?? '—',
                $row['tcv_pct'] ?? '—',
                $row['flag'],
            ], $rows),
        );
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function reportWater(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->error('WATER (invariant 9) — chlorine over 0.1 ppm blocks the first session of the day:');

        $this->table(
            ['Date', 'Source', 'System', 'Parameter', 'Value', 'Limit', 'Action taken'],
            array_map(fn (array $row): array => [
                $row['on_date'],
                $row['source'],
                $row['system_name'],
                $row['parameter'],
                $row['value'],
                $row['limit_value'],
                $row['action_taken'] ?? '— none recorded —',
            ], $rows),
        );
    }
}
