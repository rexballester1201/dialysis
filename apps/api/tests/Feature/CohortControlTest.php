<?php

declare(strict_types=1);

use App\Domain\Clinical\Models\TreatmentSession;
use App\Domain\Core\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Serology, cohort and the detective controls
|--------------------------------------------------------------------------
| The infection-control cohort is not a column anyone sets. It falls out of the
| latest serology per marker, through v_patient_cohort. So filing one HBsAg
| result silently changes which chair a patient may use -- including for sessions
| already on tomorrow's board.
|
| Invariant 1 is detective, not preventive: nothing blocks the booking. What must
| not happen is the change passing unnoticed.
*/

uses(TestCase::class, RefreshDatabase::class);

it('moves a patient to the hbv cohort when HBsAg comes back reactive', function () {
    $patient = Patient::factory()->cleanCohort()->create();

    expect($patient->cohort()->value)->toBe('clean');

    $response = $this->actingAs(staffWithRole('nephrologist'), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/serology", [
            'marker' => 'hbsag',
            'result' => 'reactive',
            'specimen_date' => now()->subDay()->toDateString(),
            'lab_name' => 'Centre lab',
        ]);

    $response->assertCreated()
        ->assertJsonPath('cohort_before', 'clean')
        ->assertJsonPath('cohort_after', 'hbv')
        ->assertJsonPath('cohort_changed', true);

    expect($patient->fresh()->cohort()->value)->toBe('hbv');
});

it('names the sessions that the new cohort no longer permits', function () {
    $patient = Patient::factory()->cleanCohort()->create();

    // S-01 is designated `clean` by the reference seed.
    $cleanStation = DB::table('stations')->where('code', 'S-01')->value('id');
    $session = TreatmentSession::factory()->create([
        'patient_id' => $patient->id,
        'station_id' => $cleanStation,
        'session_date' => now()->addDay()->toDateString(),
    ]);

    $response = $this->actingAs(staffWithRole('nephrologist'), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/serology", [
            'marker' => 'hbsag',
            'result' => 'reactive',
            'specimen_date' => now()->toDateString(),
        ]);

    $response->assertCreated()->assertJsonPath('cohort_changed', true);

    $affected = $response->json('affected_sessions');

    expect($affected)->toHaveCount(1)
        ->and($affected[0]['session_id'])->toBe($session->id)
        ->and($affected[0]['cohort'])->toBe('hbv')
        ->and($affected[0]['station_code'])->toBe('S-01');

    // Detective, not preventive: the booking still stands. That is the design.
    expect($session->fresh()->station_id)->toBe($cleanStation);
});

it('says nothing changed when a result confirms the existing cohort', function () {
    $patient = Patient::factory()->cleanCohort()->create();

    $this->actingAs(staffWithRole('nephrologist'), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/serology", [
            'marker' => 'hbsag',
            'result' => 'non_reactive',
            'specimen_date' => now()->toDateString(),
        ])
        ->assertCreated()
        ->assertJsonPath('cohort_changed', false)
        ->assertJsonPath('affected_sessions', []);
});

it('treats a repeat specimen on the same date as a correction, not a second result', function () {
    $patient = Patient::factory()->create();
    $nephrologist = staffWithRole('nephrologist');
    $date = now()->subDays(2)->toDateString();

    $this->actingAs($nephrologist, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/serology", [
            'marker' => 'hbsag', 'result' => 'indeterminate', 'specimen_date' => $date,
        ])->assertCreated();

    // serology_uq is (patient, marker, specimen_date). A re-run of the same
    // specimen replaces the verdict rather than colliding with it.
    $this->actingAs($nephrologist, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/serology", [
            'marker' => 'hbsag', 'result' => 'reactive', 'specimen_date' => $date,
        ])->assertCreated();

    expect(DB::table('serology_results')->where('patient_id', $patient->id)->count())->toBe(1)
        ->and(DB::table('serology_results')->where('patient_id', $patient->id)->value('result'))->toBe('reactive')
        ->and($patient->fresh()->cohort()->value)->toBe('hbv');
});

it('refuses a specimen drawn in the future', function () {
    $patient = Patient::factory()->create();

    $this->actingAs(staffWithRole('nephrologist'), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/serology", [
            'marker' => 'hbsag',
            'result' => 'reactive',
            'specimen_date' => now()->addDay()->toDateString(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('specimen_date');
});

it('reports a clean bill when nothing is outstanding', function () {
    $this->artisan('dialysis:check-controls')
        ->expectsOutputToContain('No outstanding violations')
        ->assertExitCode(0);
});

it('fails the scheduled check when a cohort violation is outstanding', function () {
    $patient = Patient::factory()->hbvReactive()->create();

    // S-01 is designated `clean`; this patient is hbv.
    $cleanStation = DB::table('stations')->where('code', 'S-01')->value('id');

    TreatmentSession::factory()->create([
        'patient_id' => $patient->id,
        'station_id' => $cleanStation,
        'session_date' => now()->toDateString(),
    ]);

    // Non-zero exit is the point: a scheduler or monitoring agent has to be able
    // to treat "violations outstanding" as a failure, not a log line nobody opens.
    $this->artisan('dialysis:check-controls')
        ->expectsOutputToContain('COHORT (invariant 1)')
        ->assertExitCode(1);
});

it('emits machine-readable findings for a monitoring agent', function () {
    $patient = Patient::factory()->hbvReactive()->create();

    // S-01 is designated `clean`; this patient is hbv.
    $cleanStation = DB::table('stations')->where('code', 'S-01')->value('id');

    TreatmentSession::factory()->create([
        'patient_id' => $patient->id,
        'station_id' => $cleanStation,
        'session_date' => now()->toDateString(),
    ]);

    $this->artisan('dialysis:check-controls --json')->assertExitCode(1);
});
