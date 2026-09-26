<?php

declare(strict_types=1);

use App\Domain\Clinical\Adequacy;
use App\Domain\Clinical\Models\TreatmentSession;
use App\Domain\Core\Models\Patient;
use App\Domain\Ops\Services\MachineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Reporting, and the nightly rollup
|--------------------------------------------------------------------------
| CLAUDE.md, MySQL rule 7: v_monthly_quality is the definition of record, but the
| dashboard reads a nightly summary table -- the view aggregates every session
| row before it can answer anything, so predicates do not push down.
|
| The summary is only ever written FROM the view. A second implementation of
| "average Kt/V this month" would eventually disagree with the first, and the one
| on the screen is the one people act on. These tests hold the two together.
|
| Also here: machine hygiene. How often a machine must be disinfected is unit
| policy, so nothing asserts an interval. What is reported is the fact -- this
| machine has run N treatments since its last recorded cycle.
*/

uses(TestCase::class, RefreshDatabase::class);

/** A completed, adequacy-bearing session for a patient in the current month. */
function reportedSession(Patient $patient, float $ktv = 1.42): TreatmentSession
{
    return TreatmentSession::factory()->completed()->create([
        'patient_id' => $patient->id,
        'session_date' => now()->startOfMonth()->addDays(2)->toDateString(),
        'ktv' => $ktv,
        'urr_pct' => 69.2,
    ]);
}

it('rebuilds the summary from the view, matching it figure for figure', function () {
    $patient = Patient::factory()->create();
    reportedSession($patient);

    $this->artisan('dialysis:summarise-quality')->assertExitCode(0);

    $view = DB::table('v_monthly_quality')->where('patient_id', $patient->id)->first();
    $summary = DB::table('monthly_quality_summaries')->where('patient_id', $patient->id)->first();

    expect($summary)->not->toBeNull()
        ->and((int) $summary->sessions)->toBe((int) $view->sessions)
        ->and((int) $summary->completed)->toBe((int) $view->completed)
        ->and((float) $summary->avg_ktv)->toBe((float) $view->avg_ktv)
        ->and((float) $summary->avg_idwg_kg)->toBe((float) $view->avg_idwg_kg)
        ->and($summary->summarised_at)->not->toBeNull();
});

it('serves the dashboard from the rollup and says how stale it is', function () {
    $patient = Patient::factory()->create(['mrn' => 'MRN-70001']);
    reportedSession($patient);

    $this->artisan('dialysis:summarise-quality')->assertExitCode(0);

    $body = $this->actingAs(staffWithRole('head_nurse'), 'sanctum')
        ->getJson('/api/v1/reports/quality')
        ->assertOk()
        ->json();

    expect($body['source'])->toContain('monthly_quality_summaries')
        ->and($body['summarised_at'])->not->toBeNull()
        ->and($body['patients'])->toHaveCount(1)
        ->and($body['patients'][0]['mrn'])->toBe('MRN-70001')
        // The targets travel with the report, from the one place they are defined.
        ->and($body['targets']['ktv'])->toBe(Adequacy::KTV_TARGET)
        ->and((float) $body['targets']['urr_pct'])->toBe(Adequacy::URR_TARGET_PCT);
});

it('goes to the view when asked for the live figure', function () {
    $patient = Patient::factory()->create();
    reportedSession($patient);

    // Deliberately never summarised. The rollup is empty; the view is not.
    $stale = $this->actingAs(staffWithRole('head_nurse'), 'sanctum')
        ->getJson('/api/v1/reports/quality')->assertOk()->json();

    $live = $this->actingAs(staffWithRole('head_nurse'), 'sanctum')
        ->getJson('/api/v1/reports/quality?live=1')->assertOk()->json();

    expect($stale['patients'])->toHaveCount(0)
        ->and($stale['summarised_at'])->toBeNull()
        ->and($live['patients'])->toHaveCount(1)
        ->and($live['source'])->toContain('live');
});

it('drops a patient from the rollup when their month empties out', function () {
    $patient = Patient::factory()->create();
    $session = reportedSession($patient);

    $this->artisan('dialysis:summarise-quality')->assertExitCode(0);
    expect(DB::table('monthly_quality_summaries')->where('patient_id', $patient->id)->count())->toBe(1);

    // The session is cancelled, so the patient has nothing that month.
    DB::table('treatment_sessions')->where('id', $session->id)->delete();

    $this->artisan('dialysis:summarise-quality')->assertExitCode(0);

    // Deleted and reinserted rather than upserted: an upsert would leave the
    // stale row behind and the dashboard would keep reporting a session that
    // no longer exists.
    expect(DB::table('monthly_quality_summaries')->where('patient_id', $patient->id)->count())->toBe(0);
});

it('rebuilds last month as well, because a month can change after it ends', function () {
    $patient = Patient::factory()->create();

    // A session signed late, dated to last month.
    TreatmentSession::factory()->completed()->create([
        'patient_id' => $patient->id,
        'session_date' => now()->subMonthNoOverflow()->startOfMonth()->addDays(3)->toDateString(),
    ]);

    $this->artisan('dialysis:summarise-quality')->assertExitCode(0);

    $lastMonth = now()->subMonthNoOverflow()->startOfMonth()->toDateString();

    expect(DB::table('monthly_quality_summaries')->where('month', $lastMonth)->count())->toBe(1);
});

it('does not inflate session counts through the event join', function () {
    // The same regression the invariants suite guards on the view: joining
    // session_events without LATERAL multiplies each session by its event count.
    // The rollup copies the view, so it inherits the fix -- and this proves it.
    $patient = Patient::factory()->create();
    $session = reportedSession($patient);

    DB::table('session_events')->insert([
        ['session_id' => $session->id, 'occurred_at' => now(), 'event_code' => 'hypotension', 'severity' => 'moderate'],
        ['session_id' => $session->id, 'occurred_at' => now(), 'event_code' => 'cramps', 'severity' => 'minor'],
    ]);

    $this->artisan('dialysis:summarise-quality')->assertExitCode(0);

    $summary = DB::table('monthly_quality_summaries')->where('patient_id', $patient->id)->first();

    expect((int) $summary->sessions)->toBe(1)
        ->and((int) $summary->sessions_with_hypotension)->toBe(1);
});

it('reports each detective control through its own endpoint', function () {
    $nurse = staffWithRole('head_nurse');

    $this->actingAs($nurse, 'sanctum')->getJson('/api/v1/reports/cohort-violations')
        ->assertOk()->assertJsonPath('violations', []);
    $this->actingAs($nurse, 'sanctum')->getJson('/api/v1/reports/water-exceptions')
        ->assertOk()->assertJsonPath('exceptions', []);
    $this->actingAs($nurse, 'sanctum')->getJson('/api/v1/reports/dialyzer-exceptions')
        ->assertOk()->assertJsonPath('dialyzers', []);
});

/* -------------------------------------------------------------------------- */
/* Machine hygiene */
/* -------------------------------------------------------------------------- */

it('counts what a machine has run since it was last disinfected', function () {
    $machine = machineFor();
    $service = app(MachineService::class);

    // Never cleaned, never used: nothing to say.
    expect($service->disinfectionState($machine->id)['never_disinfected'])->toBeTrue()
        ->and($service->hygieneWarnings($machine->id))->toBe([]);

    TreatmentSession::factory()->inProgress()->create(['machine_id' => $machine->id]);

    $warnings = $service->hygieneWarnings($machine->id);

    // A fact, not a verdict: the interval belongs in the unit's SOP.
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('no disinfection cycle on record')
        ->and($warnings[0])->toContain($machine->asset_tag);
});

it('clears the hygiene warning once a cycle is recorded', function () {
    $machine = machineFor();
    $service = app(MachineService::class);

    TreatmentSession::factory()->inProgress()->create(['machine_id' => $machine->id]);

    expect($service->hygieneWarnings($machine->id))->toHaveCount(1);

    $this->actingAs(staffWithRole('technician'), 'sanctum')
        ->postJson("/api/v1/machines/{$machine->id}/disinfection", ['method' => 'heat'])
        ->assertCreated();

    expect($service->hygieneWarnings($machine->id))->toBe([])
        ->and($service->disinfectionState($machine->id)['sessions_since'])->toBe(0);
});

it('tells the nurse at the chair when the machine has not been cleaned since its last patient', function () {
    passingWaterCheck();

    $machine = machineFor();

    // A previous patient, and no cycle since.
    TreatmentSession::factory()->completed()->create([
        'machine_id' => $machine->id,
        'session_date' => now()->subDay()->toDateString(),
    ]);

    $session = TreatmentSession::factory()->create([
        'status' => 'checked_in',
        'session_date' => now()->toDateString(),
        'checked_in_at' => now()->subMinutes(10),
        'pre_weight_kg' => '60.40',
    ]);

    $body = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/start", ['machine_id' => $machine->id])
        ->assertOk()
        ->json();

    // Surfaced, not refused: whether this is acceptable is unit policy, and the
    // nurse at the chair is the one who can act on it.
    expect($body['status'])->toBe('in_progress')
        ->and($body['machine_warnings'])->toHaveCount(1)
        ->and($body['machine_warnings'][0])->toContain($machine->asset_tag);
});
