<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Clinical\Models\TreatmentSession;
use App\Domain\Core\Models\Patient;
use App\Domain\Core\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<TreatmentSession>
 */
final class TreatmentSessionFactory extends Factory
{
    protected $model = TreatmentSession::class;

    /**
     * Rotates the chair between sessions.
     *
     * `ts_slot_station_uq` allows one patient per chair per shift per day, so
     * two factory-made sessions that happened to draw the same chair would
     * collide and the failure would look like a bug in whatever test drew them.
     * Tests that mean to collide pass station_id explicitly.
     */
    private static int $slot = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // General chairs only. The isolation stations are designated hbv/hcv in
        // station_cohorts, so seating a default (clean) patient in one would be
        // a cohort violation -- a real one, which v_cohort_violation would then
        // report in every unrelated test.
        $stations = DB::table('stations')
            ->join('station_cohorts as sc', 'sc.station_id', '=', 'stations.id')
            ->where('sc.cohort', 'clean')
            ->orderBy('stations.id')
            ->pluck('stations.id')
            ->all();

        $shifts = DB::table('shifts')->orderBy('id')->pluck('id')->all();

        $index = self::$slot++;

        return [
            'patient_id' => Patient::factory(),
            'session_date' => now()->toDateString(),
            'station_id' => $stations === [] ? null : $stations[$index % count($stations)],
            'shift_id' => $shifts === [] ? null : $shifts[0],
            'status' => 'scheduled',
            'modality' => 'hd',
            // idwg_kg, weight_loss_kg, actual_duration_min, slot_patient_key and
            // slot_station_key are generated columns -- MySQL computes them.
        ];
    }

    /** Checked in, needled, running. What the flow sheet writes against. */
    public function inProgress(): self
    {
        return $this->state(fn (): array => [
            'status' => 'in_progress',
            'checked_in_at' => now()->subMinutes(270),
            'started_at' => now()->subMinutes(255),
            'pre_weight_kg' => 61.20,
            'dry_weight_kg' => 57.50,
            'pre_bp_sys' => 148,
            'pre_bp_dia' => 84,
            'pre_pulse' => 80,
            'planned_duration_min' => 240,
            // A running session has a named nurse at the chair, and
            // SessionLockService refuses to sign a record without one.
            'primary_nurse_id' => Staff::factory()->withRole('nurse'),
        ]);
    }

    /** Finished and charted, but not yet signed -- so still editable. */
    public function completed(): self
    {
        return $this->inProgress()->state(fn (): array => [
            'status' => 'completed',
            'ended_at' => now()->subMinutes(15),
            'post_weight_kg' => 57.60,
            'net_uf_ml' => 2800,
            'ktv' => 1.42,
            'ktv_method' => 'single_pool_daugirdas',
            'urr_pct' => 69.2,
            'termination_reason' => 'completed_as_prescribed',
            'discharge_condition' => 'stable',
            'ambulation' => 'unassisted',
            'discharged_at' => now()->subMinutes(5),
        ]);
    }

    /**
     * Both signatures in and locked_at set: immutable except through the
     * amendment path. The `treatment_sessions_bu` trigger enforces that, so a
     * locked session built here behaves exactly like one locked in production.
     */
    public function locked(): self
    {
        return $this->completed()->state(fn (): array => [
            'nurse_signed_by' => Staff::factory()->withRole('nurse'),
            'nurse_signed_at' => now()->subMinutes(10),
            'physician_signed_by' => Staff::factory()->withRole('nephrologist'),
            'physician_signed_at' => now()->subMinutes(8),
            'locked_at' => now()->subMinutes(8),
        ]);
    }
}
