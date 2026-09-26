<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Enums\Cohort;
use App\Domain\Clinical\Models\SerologyResult;
use App\Domain\Core\Models\Patient;
use App\Domain\Core\Models\Staff;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recording serology, and saying out loud when it moves a patient.
 *
 * The infection-control cohort is not a column anyone sets. It is derived from
 * the latest result per marker through v_patient_cohort, so filing an HBsAg
 * result can silently change which chair and which machine a patient may use --
 * including for sessions that are already on the board.
 *
 * Invariant 1 is detective, not preventive: nothing here blocks a booking. What
 * this service does is refuse to let the change pass unremarked, by returning
 * the sessions that are now non-conforming so a human is told at the moment the
 * result is filed rather than by a report the next morning.
 */
final class SerologyService
{
    /**
     * File a result and report what it changed.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{result: SerologyResult, cohort_before: Cohort, cohort_after: Cohort, affected_sessions: list<array<string, mixed>>}
     */
    public function record(Patient $patient, array $attributes, Staff $actor): array
    {
        $before = $patient->cohort();

        $result = DB::transaction(fn (): SerologyResult => SerologyResult::updateOrCreate(
            // serology_uq is (patient, marker, specimen_date): a re-run of the
            // same specimen is a correction, not a second result.
            [
                'patient_id' => $patient->id,
                'marker' => $attributes['marker'],
                'specimen_date' => $attributes['specimen_date'],
            ],
            $attributes + [
                'recorded_by' => $actor->id,
                'recorded_at' => Carbon::now(),
            ],
        ));

        $after = $patient->cohort();

        return [
            'result' => $result,
            'cohort_before' => $before,
            'cohort_after' => $after,
            'affected_sessions' => $before === $after ? [] : $this->nonConformingSessions($patient),
        ];
    }

    /**
     * Sessions already booked for this patient that their new cohort no longer
     * permits. Read from v_cohort_violation so PHP and SQL cannot disagree about
     * what counts as a violation.
     *
     * @return list<array<string, mixed>>
     */
    public function nonConformingSessions(Patient $patient): array
    {
        $rows = [];

        $found = DB::table('v_cohort_violation')
            ->join('treatment_sessions as ts', 'ts.id', '=', 'v_cohort_violation.session_id')
            ->where('ts.patient_id', $patient->id)
            ->orderBy('v_cohort_violation.session_date')
            ->get();

        foreach ($found as $row) {
            $rows[] = [
                'session_id' => $row->session_id,
                'session_date' => $row->session_date,
                'cohort' => $row->cohort,
                'station_code' => $row->station_code,
                'asset_tag' => $row->asset_tag,
                'dedicated_cohort' => $row->dedicated_cohort,
            ];
        }

        return $rows;
    }
}
