<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Enums\Cohort;
use App\Domain\Core\Models\Patient;
use Illuminate\Support\Facades\DB;

/**
 * Infection-control segregation. The single highest-consequence rule in a
 * dialysis unit: an HBsAg-reactive patient must never occupy a chair or
 * machine designated for another cohort.
 *
 * Checked here at assignment time, and detectable after the fact through
 * the v_cohort_violation view, which a nightly job reports on.
 */
final class CohortGuard
{
    public function cohortFor(Patient $patient): Cohort
    {
        return $patient->cohort();
    }

    public function stationAccepts(int $stationId, Cohort $cohort): bool
    {
        $declared = DB::table('station_cohorts')->where('station_id', $stationId)->pluck('cohort');

        // A station with no declared cohorts is unrestricted.
        return $declared->isEmpty() || $declared->contains($cohort->value);
    }

    public function machineAccepts(int $machineId, Cohort $cohort): bool
    {
        $dedicated = DB::table('machines')->where('id', $machineId)->value('dedicated_cohort');

        return $dedicated === null || $dedicated === $cohort->value;
    }

    /** @return list<string> human-readable reasons; empty means the assignment is safe */
    public function violations(Patient $patient, ?int $stationId, ?int $machineId): array
    {
        $cohort = $this->cohortFor($patient);
        $out = [];

        if ($stationId !== null && ! $this->stationAccepts($stationId, $cohort)) {
            $code = DB::table('stations')->where('id', $stationId)->value('code');
            $out[] = "Station {$code} is not designated for the {$cohort->label()} cohort.";
        }

        if ($machineId !== null && ! $this->machineAccepts($machineId, $cohort)) {
            $tag = DB::table('machines')->where('id', $machineId)->value('asset_tag');
            $out[] = "Machine {$tag} is dedicated to a different cohort.";
        }

        return $out;
    }

    /** Stations that are free for this patient in a given date/shift slot. */
    /** @return array<int, \stdClass> */
    public function availableStations(Patient $patient, string $date, int $shiftId): array
    {
        $cohort = $this->cohortFor($patient);

        return DB::table('stations as st')
            ->where('st.is_active', 1)
            ->where(function ($q) use ($cohort) {
                $q->whereNotExists(fn ($s) => $s->from('station_cohorts as sc')
                    ->whereColumn('sc.station_id', 'st.id'))
                    ->orWhereExists(fn ($s) => $s->from('station_cohorts as sc')
                        ->whereColumn('sc.station_id', 'st.id')
                        ->where('sc.cohort', $cohort->value));
            })
            ->whereNotExists(fn ($s) => $s->from('treatment_sessions as ts')
                ->whereColumn('ts.station_id', 'st.id')
                ->where('ts.session_date', $date)
                ->where('ts.shift_id', $shiftId)
                ->whereNotIn('ts.status', ['cancelled', 'missed', 'refused']))
            ->orderBy('st.code')
            ->get(['st.id', 'st.code', 'st.kind'])
            ->all();
    }
}
