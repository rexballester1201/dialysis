<?php

declare(strict_types=1);

namespace App\Domain\Ops\Services;

use App\Domain\Core\Services\FacilityCalendar;
use Illuminate\Support\Facades\DB;

/**
 * Reads the three detective controls.
 *
 * Invariants 1, 8 and 9 are enforced by views that *find* violations rather than
 * block them:
 *
 *   v_cohort_violation  an HBsAg-reactive patient in a chair or on a machine
 *                       their cohort does not permit
 *   v_dialyzer_status   a reused dialyzer below 80% TCV or past its reuse count
 *   v_water_exceptions  total chlorine over 0.1 ppm, or a failed water test
 *
 * A detective control nobody reads is not a control (CLAUDE.md). This class is
 * the reader; CheckDetectiveControls is the scheduled job that calls it and puts
 * the result in front of a human.
 *
 * It deliberately reports rather than repairs. Reseating a patient or condemning
 * a dialyzer is a clinical decision, and a job that quietly "fixed" a cohort
 * violation by moving a booking would hide the fact that it happened at all.
 *
 * Each method maps its view's columns out by name. That is a few more lines than
 * handing back raw rows, and it puts the shape each view promises in exactly one
 * place -- which is also what lets the caller be type-checked.
 */
final class DetectiveControlReader
{
    public function __construct(private readonly FacilityCalendar $calendar) {}

    /**
     * Every outstanding violation, grouped by control.
     *
     * @return array{
     *     cohort: list<array<string, mixed>>,
     *     dialyzer: list<array<string, mixed>>,
     *     water: list<array<string, mixed>>
     * }
     */
    public function all(): array
    {
        return [
            'cohort' => $this->cohortViolations(),
            'dialyzer' => $this->dialyzerViolations(),
            'water' => $this->waterExceptions(),
        ];
    }

    /**
     * Patients seated against their infection-control cohort.
     *
     * Scoped to today onward by default: a violation on a session that has
     * already run is a reportable incident rather than something the ward can
     * still act on, and mixing the two buries the actionable ones.
     *
     * @return list<array<string, mixed>>
     */
    public function cohortViolations(?string $fromDate = null): array
    {
        $rows = [];

        $found = DB::table('v_cohort_violation')
            ->where('session_date', '>=', $fromDate ?? $this->calendar->todayString())
            ->orderBy('session_date')
            ->get();

        foreach ($found as $row) {
            $rows[] = [
                'session_id' => $row->session_id,
                'session_date' => $row->session_date,
                'mrn' => $row->mrn,
                'full_name' => $row->full_name,
                'cohort' => $row->cohort,
                'station_code' => $row->station_code,
                'asset_tag' => $row->asset_tag,
                'dedicated_cohort' => $row->dedicated_cohort,
            ];
        }

        return $rows;
    }

    /**
     * Dialyzers that must not be issued again: below 80% of new total cell
     * volume, or at their reuse count.
     *
     * @return list<array<string, mixed>>
     */
    public function dialyzerViolations(): array
    {
        $rows = [];

        $found = DB::table('v_dialyzer_status')
            ->whereIn('flag', ['discard_tcv', 'discard_count'])
            ->orderBy('label_code')
            ->get();

        foreach ($found as $row) {
            $rows[] = [
                'label_code' => $row->label_code,
                'mrn' => $row->mrn,
                'full_name' => $row->full_name,
                'dialyzer' => $row->dialyzer,
                'use_count' => $row->use_count,
                'max_reuse_count' => $row->max_reuse_count,
                'tcv_pct' => $row->tcv_pct,
                'flag' => $row->flag,
            ];
        }

        return $rows;
    }

    /**
     * Water exceptions. Total chlorine over 0.1 ppm blocks the day's first
     * session, so an unread exception here is the one that reaches a patient
     * fastest.
     *
     * @return list<array<string, mixed>>
     */
    public function waterExceptions(): array
    {
        $rows = [];

        $found = DB::table('v_water_exceptions')->orderByDesc('on_date')->get();

        foreach ($found as $row) {
            $rows[] = [
                'source' => $row->source,
                'on_date' => $row->on_date,
                'system_name' => $row->system_name,
                'parameter' => $row->parameter,
                'value' => $row->value,
                'limit_value' => $row->limit_value,
                'action_taken' => $row->action_taken,
            ];
        }

        return $rows;
    }
}
