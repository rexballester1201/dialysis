<?php

declare(strict_types=1);

use App\Domain\Clinical\Models\TreatmentSession;
use App\Domain\Core\Models\Patient;
use App\Domain\Core\Models\Staff;
use App\Domain\Ops\Models\DialyzerUnit;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Test helpers
|--------------------------------------------------------------------------
| Shared by ClinicalInvariantsTest and SyncProtocolTest. They deliberately read
| reference data out of the database rather than restating it: a benefit rate or
| session cap written into a test is a rate that will be wrong the next time
| PhilHealth issues a circular, and the test would keep passing while the code
| billed the wrong amount.
*/

/**
 * A staff member holding one role.
 *
 * The role code must exist in `roles` (loaded by the reference seed) or the
 * insert fails on the foreign key -- which is the point. A test must not be able
 * to grant a permission the real system has never heard of.
 */
function staffWithRole(string $role): Staff
{
    return Staff::factory()->withRole($role)->create();
}

/**
 * Log a passing pre-dialysis water check for a date.
 *
 * Invariant 9 blocks the first treatment of a day until total chlorine has been
 * measured and is within the 0.1 ppm action limit. Any test that starts a
 * session has to satisfy that first -- which is the rule doing its job, so the
 * helper is explicit rather than hidden in a factory.
 */
function passingWaterCheck(?CarbonInterface $date = null, float $chlorinePpm = 0.02): void
{
    DB::table('water_daily_logs')->insert([
        'water_system_id' => DB::table('water_systems')->value('id'),
        'logged_at' => ($date ?? now())->copy()->setTime(5, 30),
        'total_chlorine_ppm' => $chlorinePpm,
        'is_out_of_range' => 0,
    ]);
}

/**
 * A dialysis machine.
 *
 * Machines are unit-specific equipment rather than reference data, so the seed
 * carries none and a test that needs one creates it.
 *
 * @param  array<string, mixed>  $overrides
 */
function machineFor(array $overrides = []): stdClass
{
    $id = DB::table('machines')->insertGetId(array_merge([
        'asset_tag' => 'M-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
        'manufacturer' => 'Fresenius',
        'model' => '4008S',
        'status' => 'in_service',
        'dedicated_cohort' => null,
    ], $overrides));

    $machine = DB::table('machines')->where('id', $id)->first();

    if (! $machine instanceof stdClass) {
        throw new RuntimeException('Machine vanished immediately after insert.');
    }

    return $machine;
}

/**
 * A reusable dialyzer belonging to one patient, with a measured starting volume.
 */
function dialyzerFor(Patient $patient, array $overrides = []): DialyzerUnit
{
    $item = DB::table('items')->where('is_reusable', 1)->first();

    return DialyzerUnit::create(array_merge([
        'item_id' => $item->id,
        'patient_id' => $patient->id,
        'label_code' => 'DZ-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
        'use_count' => 0,
        'initial_tcv_ml' => '110.0',
        'current_tcv_ml' => '110.0',
        'status' => 'active',
    ], $overrides));
}

/**
 * A claim row for this session's patient, priced from the benefit program that
 * is actually in force on the session date.
 *
 * `claim_no` is left null on purpose: it carries a UNIQUE index, and the
 * double-billing test inserts two claims for one session. Filling it would make
 * that test fail on the wrong constraint and prove nothing about `claim_sessions`.
 *
 * @return array<string, mixed>
 */
function claimAttributes(TreatmentSession $session): array
{
    $program = benefitProgramOn($session);

    $serviceDate = $session->session_date->toDateString();

    return [
        'patient_id' => $session->patient_id,
        'payer_id' => $program->payer_id,
        'program_id' => $program->id,
        'service_from' => $serviceDate,
        'service_to' => $serviceDate,
        'session_count' => 1,
        'amount_claimed' => $program->case_rate,
        'status' => 'draft',
    ];
}

/**
 * Put this session's patient on the PhilHealth package for the calendar year
 * and bill this one session against it.
 *
 * Leaves v_benefit_utilisation reporting the program's own allotment, one
 * session claimed, and the remainder outstanding.
 */
function seedPhilHealthBenefit(TreatmentSession $session): void
{
    $program = benefitProgramOn($session);
    $date = $session->session_date;

    $periodId = DB::table('benefit_periods')->insertGetId([
        'patient_id' => $session->patient_id,
        'program_id' => $program->id,
        'period_start' => $date->copy()->startOfYear()->toDateString(),
        'period_end' => $date->copy()->endOfYear()->toDateString(),
        // Read from the effective-dated row, never hardcoded: the cap has
        // already moved from 90 to 156 once.
        'sessions_allotted' => $program->sessions_per_period,
    ]);

    $claimId = DB::table('claims')->insertGetId(
        claimAttributes($session) + ['benefit_period_id' => $periodId]
    );

    DB::table('claim_sessions')->insert([
        'session_id' => $session->id,
        'claim_id' => $claimId,
        'benefit_seq_no' => 1,
        'amount' => $program->case_rate,
    ]);
}

/**
 * The haemodialysis benefit program in force on the session date.
 *
 * benefit_programs rows are effective-dated because the rate and the cap keep
 * moving; the superseded 2023 package is still in the table so historical
 * claims re-price correctly.
 */
function benefitProgramOn(TreatmentSession $session): object
{
    $date = $session->session_date->toDateString();

    $program = DB::table('benefit_programs')
        ->where('modality', 'hd')
        ->where('effective_from', '<=', $date)
        ->where(function ($query) use ($date): void {
            $query->whereNull('effective_to')->orWhere('effective_to', '>', $date);
        })
        ->orderByDesc('effective_from')
        ->first();

    if ($program === null) {
        throw new RuntimeException("No haemodialysis benefit program is effective on {$date}.");
    }

    return $program;
}

/**
 * Put the unit in a timezone for one test.
 *
 * TestCase starts every test on a UTC unit, so dates built from now() mean the
 * same day to the suite and to FacilityCalendar. The tests about the day
 * boundary opt back into a real zone with this, and pin the clock to an hour
 * where the unit's calendar and the UTC calendar name different days.
 */
function unitIn(string $zone): void
{
    DB::table('facilities')->where('id', 1)->update(['timezone' => $zone]);
}
