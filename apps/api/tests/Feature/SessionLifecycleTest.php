<?php

declare(strict_types=1);

use App\Domain\Clinical\Models\HdPrescription;
use App\Domain\Clinical\Models\TreatmentSession;
use App\Domain\Core\Models\Patient;
use App\Domain\Core\Models\Staff;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| The treatment record, check-in to lock
|--------------------------------------------------------------------------
| scheduled -> checked_in -> in_progress -> completed -> nurse signs ->
| physician signs -> locked. After the lock the only way in is the amendment
| path, and the treatment_sessions_bu trigger enforces that whether or not the
| service is the one asking.
*/

uses(TestCase::class, RefreshDatabase::class);

function ward(): Staff
{
    return staffWithRole('nurse');
}

function physician(): Staff
{
    return staffWithRole('nephrologist');
}

/** A scheduled session for a patient with a dry weight already on record. */
function scheduledSession(string $dryWeight = '57.50'): TreatmentSession
{
    // Invariant 9 gates the first treatment of the day on a passing water check.
    // Any session that will be started needs one on record first.
    passingWaterCheck();

    $patient = Patient::factory()->create();

    DB::table('dry_weights')->insert([
        'patient_id' => $patient->id,
        'weight_kg' => $dryWeight,
        'effective_from' => now()->subMonth()->toDateString(),
    ]);

    return TreatmentSession::factory()->create([
        'patient_id' => $patient->id,
        'status' => 'scheduled',
        'session_date' => now()->toDateString(),
    ]);
}

it('runs a session from check-in to lock', function () {
    $session = scheduledSession();
    $nurse = ward();
    $doctor = physician();

    // ---- check in ----
    $this->actingAs($nurse, 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/check-in", [
            'pre_weight_kg' => '60.40',
            'pre_bp_sys' => 148,
            'pre_bp_dia' => 84,
            'pre_pulse' => 80,
        ])
        ->assertOk()
        ->assertJsonPath('status', 'checked_in')
        // Snapshotted from the effective-dated dry weight, and IDWG falls out of
        // it as a generated column: 60.40 - 57.50.
        ->assertJsonPath('dry_weight_kg', '57.50')
        ->assertJsonPath('idwg_kg', '2.90');

    // ---- start ----
    $this->actingAs($nurse, 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/start", [
            'blood_flow_set_ml_min' => 300,
            'dialysate_flow_ml_min' => 500,
            'anticoagulant' => 'heparin',
        ])
        ->assertOk()
        ->assertJsonPath('status', 'in_progress');

    // ---- end ----
    $this->actingAs($nurse, 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/end", [
            'post_weight_kg' => '57.60',
            'net_uf_ml' => 2800,
            'ktv' => 1.42,
            'ktv_method' => 'single_pool_daugirdas',
            'urr_pct' => 69.2,
            'discharge_condition' => 'stable',
        ])
        ->assertOk()
        ->assertJsonPath('status', 'completed')
        // pre - post, computed by MySQL.
        ->assertJsonPath('weight_loss_kg', '2.80');

    // ---- attestation ----
    $this->actingAs($nurse, 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/sign/nurse")
        ->assertOk()
        ->assertJsonPath('is_locked', false);

    $this->actingAs($doctor, 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/sign/physician")
        ->assertOk()
        ->assertJsonPath('is_locked', true);

    expect($session->fresh()->locked_at)->not->toBeNull();
});

it('pins the session to the prescription version in force that day', function () {
    $session = scheduledSession();
    $doctor = physician();

    $this->actingAs($doctor, 'sanctum')
        ->postJson("/api/v1/patients/{$session->patient->public_id}/prescriptions", [
            'effective_from' => now()->subMonths(2)->toDateString(),
            'reason' => 'initial prescription',
            'modality' => 'hd',
            'duration_min' => 240,
            'sessions_per_week' => 3,
            'anticoagulant' => 'heparin',
        ])
        ->assertCreated();

    $this->actingAs(ward(), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/check-in", ['pre_weight_kg' => '60.40'])
        ->assertOk()
        // Planned duration comes off the prescription rather than being retyped.
        ->assertJsonPath('planned_duration_min', 240);

    $fresh = $session->fresh();

    expect($fresh->prescription_id)->not->toBeNull()
        ->and($fresh->prescription->duration_min)->toBe(240);
});

it('will not start a session that was never checked in', function () {
    $session = scheduledSession();

    $this->actingAs(ward(), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/start", [])
        ->assertStatus(422)
        ->assertJsonPath('message', 'A session that is scheduled cannot become in_progress. Expected one of: checked_in.');

    expect($session->fresh()->started_at)->toBeNull();
});

it('will not end a session that was never started', function () {
    $session = scheduledSession();

    $this->actingAs(ward(), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/check-in", ['pre_weight_kg' => '60.40'])
        ->assertOk();

    $this->actingAs(ward(), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/end", ['post_weight_kg' => '57.60'])
        ->assertStatus(422);

    expect($session->fresh()->ended_at)->toBeNull();
});

it('records a treatment stopped early as aborted, not completed', function () {
    $session = scheduledSession();
    $nurse = ward();

    $this->actingAs($nurse, 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/check-in", ['pre_weight_kg' => '60.40'])->assertOk();
    $this->actingAs($nurse, 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/start", [])->assertOk();

    // A run cut short by hypotension is not a completed treatment, and must not
    // be counted as one in the monthly adequacy numbers.
    $this->actingAs($nurse, 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/end", [
            'post_weight_kg' => '59.10',
            'termination_reason' => 'hypotension',
            'termination_notes' => 'symptomatic at 140 minutes',
        ])
        ->assertOk()
        ->assertJsonPath('status', 'aborted')
        ->assertJsonPath('termination_reason', 'hypotension');
});

it('refuses to sign a record with the mandatory fields missing', function () {
    $session = scheduledSession();

    $this->actingAs(ward(), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/check-in", ['pre_weight_kg' => '60.40'])->assertOk();

    // No post weight, no start, no end: not a signable record.
    $this->actingAs(ward(), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/sign/nurse")
        ->assertStatus(422);

    expect($session->fresh()->nurse_signed_at)->toBeNull();
});

it('will not let a nurse countersign as the physician', function () {
    $session = TreatmentSession::factory()->completed()->create();

    $this->actingAs(ward(), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/sign/physician")
        ->assertForbidden();

    expect($session->fresh()->physician_signed_at)->toBeNull();
});

it('appends vitals in time order and refuses a second reading at the same instant', function () {
    $session = TreatmentSession::factory()->inProgress()->create();
    $nurse = ward();
    $at = now()->subMinutes(30)->format('Y-m-d H:i:s');

    $this->actingAs($nurse, 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/vitals", [
            'recorded_at' => $at, 'bp_sys' => 148, 'bp_dia' => 84, 'pulse' => 80,
        ])
        ->assertCreated()
        // map_mmhg is generated: dia + (sys - dia)/3.
        ->assertJsonPath('map_mmhg', '105.3');

    // Same instant, same session: keyed by (session_id, recorded_at), so this is
    // a duplicate rather than a second reading. That is what makes the flow
    // sheet conflict-free across an offline tablet and the desk.
    $this->actingAs($nurse, 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/vitals", [
            'recorded_at' => $at, 'bp_sys' => 150,
        ])
        ->assertStatus(409);

    expect($session->vitals()->count())->toBe(1)
        ->and($session->vitals()->first()->bp_sys)->toBe(148);
});

it('rejects a physiologically impossible observation', function () {
    $session = TreatmentSession::factory()->inProgress()->create();

    $this->actingAs(ward(), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/vitals", [
            'recorded_at' => now()->format('Y-m-d H:i:s'),
            'pulse' => 900,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('pulse');
});

it('records an intra-dialytic event against a known code', function () {
    $session = TreatmentSession::factory()->inProgress()->create();
    $nurse = ward();

    $this->actingAs($nurse, 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/events", [
            'event_code' => 'hypotension',
            'occurred_at' => now()->format('Y-m-d H:i:s'),
            'severity' => 'moderate',
            'intervention' => 'trendelenburg, 200 mL saline, UF off',
        ])
        ->assertCreated()
        ->assertJsonPath('event_code', 'hypotension');

    // event_code is a foreign key into event_refs; a typo is a field error, not
    // a new category nothing reports on.
    $this->actingAs($nurse, 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/events", [
            'event_code' => 'hypotensionn',
            'occurred_at' => now()->format('Y-m-d H:i:s'),
            'severity' => 'moderate',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('event_code');
});

it('requires a witness for a high-alert medication, and names it', function () {
    $session = TreatmentSession::factory()->inProgress()->create();
    $heparin = DB::table('medication_refs')->where('is_high_alert', 1)->first();

    $response = $this->actingAs(ward(), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/medications", [
            'medication_id' => $heparin->id,
            'dose' => 2000,
            'dose_unit' => 'IU',
            'route' => 'IV',
        ]);

    $response->assertStatus(422);

    expect($response->json('message'))->toContain($heparin->generic_name)
        ->and($response->json('message'))->toContain('witness')
        ->and(DB::table('medication_administrations')->count())->toBe(0);
});

it('refuses a high-alert dose the giver witnessed themselves', function () {
    $session = TreatmentSession::factory()->inProgress()->create();
    $heparin = DB::table('medication_refs')->where('is_high_alert', 1)->value('id');
    $nurse = ward();

    // The database only requires witnessed_by to be non-null. A nurse witnessing
    // their own administration satisfies that and defeats the entire control, so
    // the service is deliberately stricter than the schema.
    $this->actingAs($nurse, 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/medications", [
            'medication_id' => $heparin,
            'dose' => 2000, 'dose_unit' => 'IU', 'route' => 'IV',
            'witnessed_by' => $nurse->id,
        ])
        ->assertStatus(422);

    expect(DB::table('medication_administrations')->count())->toBe(0);
});

it('accepts a high-alert dose with a second person witnessing', function () {
    $session = TreatmentSession::factory()->inProgress()->create();
    $heparin = DB::table('medication_refs')->where('is_high_alert', 1)->value('id');

    $this->actingAs(ward(), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/medications", [
            'medication_id' => $heparin,
            'dose' => 2000, 'dose_unit' => 'IU', 'route' => 'IV',
            'witnessed_by' => staffWithRole('head_nurse')->id,
        ])
        ->assertCreated()
        ->assertJsonPath('witnessed', true);

    expect(DB::table('medication_administrations')->count())->toBe(1);
});

it('does not demand a witness for an ordinary medication', function () {
    $session = TreatmentSession::factory()->inProgress()->create();
    $ordinary = DB::table('medication_refs')->where('is_high_alert', 0)->value('id');

    $this->actingAs(ward(), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/medications", [
            'medication_id' => $ordinary,
            'dose' => 1, 'dose_unit' => 'amp', 'route' => 'IV',
        ])
        ->assertCreated()
        ->assertJsonPath('witnessed', false);
});

it('refuses to chart onto a locked session and says why', function () {
    $session = TreatmentSession::factory()->locked()->create();

    $response = $this->actingAs(ward(), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/vitals", [
            'recorded_at' => now()->format('Y-m-d H:i:s'),
            'bp_sys' => 120,
        ]);

    // 409, not 422: the request was fine, the caller's view of the record is
    // stale. The message points at the amendment path.
    $response->assertStatus(409);

    expect($response->json('message'))->toContain('amendment')
        ->and($session->vitals()->count())->toBe(0);
});

it('amends a locked record without overwriting the original', function () {
    $session = TreatmentSession::factory()->locked()->create(['ktv' => 1.42]);
    $doctor = physician();

    $this->actingAs($doctor, 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/amend", [
            'reason' => 'lab recalculated the post-BUN',
            'changes' => ['ktv' => 1.45],
        ])
        ->assertOk()
        ->assertJsonPath('ktv', '1.45');

    // The correction leaves a trail in two places: a session note and a full
    // before/after pair in audit_logs.
    $note = DB::table('session_notes')->where('session_id', $session->id)->latest('id')->first();

    expect($note->body)->toContain('AMENDMENT')
        ->and($note->body)->toContain('lab recalculated')
        ->and($note->body)->toContain('1.42')
        ->and(DB::table('audit_logs')
            ->where('auditable_id', $session->id)
            ->where('action', 'UPDATE')
            ->count())->toBeGreaterThan(0);

    // Still locked afterwards -- amending does not reopen the record.
    expect($session->fresh()->isLocked())->toBeTrue();
});

it('will not amend without a reason', function () {
    $session = TreatmentSession::factory()->locked()->create(['ktv' => 1.42]);

    $this->actingAs(physician(), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/amend", [
            'changes' => ['ktv' => 1.45],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    expect($session->fresh()->ktv)->toEqual('1.42');
});

it('will not let a nurse amend a signed record', function () {
    $session = TreatmentSession::factory()->locked()->create(['ktv' => 1.42]);

    // Only a physician may amend a signed record.
    $this->actingAs(ward(), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/amend", [
            'reason' => 'looks wrong to me',
            'changes' => ['ktv' => 1.9],
        ])
        ->assertForbidden();

    expect($session->fresh()->ktv)->toEqual('1.42');
});

it('refuses to widen one prescription over another', function () {
    // hd_prescriptions_bi guards the insert and is covered by the invariants
    // suite. This is the BEFORE UPDATE half: two versions that do not overlap,
    // and an edit that reopens the closed one across the live one.
    $patient = Patient::factory()->create();

    $first = HdPrescription::create([
        'patient_id' => $patient->id,
        'version' => 1,
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-06-01',
        'duration_min' => 240,
    ]);

    HdPrescription::create([
        'patient_id' => $patient->id,
        'version' => 2,
        'effective_from' => '2026-06-01',
        'duration_min' => 270,
    ]);

    // Clearing the end date would leave version 1 running underneath version 2,
    // and a session in July would resolve to two prescriptions at once.
    expect(fn () => $first->forceFill(['effective_to' => null])->save())
        ->toThrow(QueryException::class);

    expect($first->fresh()->effective_to->toDateString())->toBe('2026-06-01');
});

it('returns the session shape the typed client parses', function () {
    // packages/api-client validates this with Zod. A renamed or dropped field
    // becomes a runtime throw in the bedside PWA rather than a compile error, so
    // the contract is pinned here instead.
    $session = TreatmentSession::factory()->completed()->create();

    $body = $this->actingAs(ward(), 'sanctum')
        ->getJson("/api/v1/sessions/{$session->public_id}")
        ->assertOk()
        ->json();

    expect($body)->toHaveKeys([
        'public_id', 'session_date', 'status', 'modality',
        'is_locked', 'locked_at', 'nurse_signed_at', 'physician_signed_at',
        'checked_in_at', 'started_at', 'ended_at', 'actual_duration_min',
        'pre_weight_kg', 'dry_weight_kg', 'post_weight_kg', 'idwg_kg', 'weight_loss_kg',
        'planned_duration_min', 'planned_uf_ml', 'net_uf_ml',
        'ktv', 'urr_pct', 'termination_reason', 'patient',
    ])
        ->and($body['patient'])->toHaveKeys(['public_id', 'mrn', 'full_name'])
        // The internal row id never leaves the server, here or anywhere.
        ->and($body)->not->toHaveKey('id')
        ->and($body)->not->toHaveKey('patient_id');

    // Weights are DECIMAL server-side and stay strings over the wire: a float
    // round-trip is exactly how a dry weight picks up a rounding error.
    expect($body['pre_weight_kg'])->toBeString();
});

it('logs a chart view of a session against the patient', function () {
    $session = TreatmentSession::factory()->create();

    $this->actingAs(ward(), 'sanctum')
        ->getJson("/api/v1/sessions/{$session->public_id}")
        ->assertOk();

    // LogRecordAccess resolves the patient through the session, so opening a
    // treatment record counts as opening that patient's chart.
    expect(DB::table('record_access_logs')->where('patient_id', $session->patient_id)->count())->toBe(1);
});

/* -------------------------------------------------------------------------- */
/* Issuing a dialyzer by the label a nurse actually scans */
/* -------------------------------------------------------------------------- */
/*
 | The dialyzer list never exposes a unit's BIGINT id, so dialyzer_unit_id was a
 | parameter no client could fill. The label is what is printed on the unit, it
 | is UNIQUE, and it is resolved across every patient on purpose -- so the wrong
 | patient's unit is refused by invariant 8, by name.
 */

/** A checked-in session, ready to start. */
function checkedInSession(): TreatmentSession
{
    $session = scheduledSession();

    test()->actingAs(ward(), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/check-in", ['pre_weight_kg' => '60.40'])
        ->assertOk();

    return $session->fresh();
}

it('issues a dialyzer by its label and records it on the session', function () {
    $session = checkedInSession();
    $unit = dialyzerFor($session->patient);

    $this->actingAs(ward(), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/start", [
            'dialyzer_label_code' => $unit->label_code,
        ])
        ->assertOk()
        ->assertJsonPath('status', 'in_progress');

    // issueTo() stamps the unit; the session has to record it too, or a reuse
    // audit cannot say which physical unit this treatment ran on.
    $row = DB::table('treatment_sessions')->where('id', $session->id)->first();

    expect((int) $row->dialyzer_unit_id)->toBe((int) $unit->id)
        ->and((int) $row->dialyzer_item_id)->toBe((int) $unit->item_id)
        ->and(DB::table('dialyzer_units')->where('id', $unit->id)->value('first_used_on'))->not->toBeNull();
});

it('refuses another patient\'s dialyzer by name rather than as not found', function () {
    $session = checkedInSession();
    $someoneElse = Patient::factory()->create();
    $theirs = dialyzerFor($someoneElse);

    $response = $this->actingAs(ward(), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/start", [
            'dialyzer_label_code' => $theirs->label_code,
        ]);

    // A cross-infection event, not a paperwork slip. Invariant 8 names it.
    $response->assertStatus(422);
    expect($response->json('message'))->toContain('must never be used on another patient')
        ->and($response->json('message'))->toContain($someoneElse->mrn)
        ->and(DB::table('treatment_sessions')->where('id', $session->id)->value('status'))->toBe('checked_in')
        ->and(DB::table('treatment_sessions')->where('id', $session->id)->value('dialyzer_unit_id'))->toBeNull();
});

it('refuses a condemned dialyzer named by label', function () {
    $session = checkedInSession();
    // 80 of 110 ml is 72.7% of the original total cell volume -- below the
    // 80% floor in v_dialyzer_status and DialyzerReuseService.
    $unit = dialyzerFor($session->patient, ['current_tcv_ml' => '80.0']);

    $this->actingAs(ward(), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/start", [
            'dialyzer_label_code' => $unit->label_code,
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'below the 80% minimum'));

    expect(DB::table('treatment_sessions')->where('id', $session->id)->value('status'))->toBe('checked_in');
});

it('rejects a label that does not exist', function () {
    $session = checkedInSession();

    $this->actingAs(ward(), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/start", [
            'dialyzer_label_code' => 'DZ-NOPE',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['dialyzer_label_code']);
});

/* -------------------------------------------------------------------------- */
/* A refused step leaves no trace on the chart */
/* -------------------------------------------------------------------------- */

it('leaves no override on the chart when the start is refused for another reason', function () {
    $session = checkedInSession();

    // An HBV patient in a clean chair: infection control refuses unless overridden.
    DB::table('serology_results')->insert([
        'patient_id' => $session->patient_id, 'marker' => 'hbsag', 'result' => 'reactive',
        'specimen_date' => now()->subDay()->toDateString(), 'recorded_at' => now(),
    ]);
    $cleanChair = DB::table('station_cohorts')->where('cohort', 'clean')->value('station_id');
    DB::table('treatment_sessions')->where('id', $session->id)->update(['station_id' => $cleanChair]);

    // And a dialyzer that belongs to someone else: invariant 8 refuses outright.
    $theirs = dialyzerFor(Patient::factory()->create());

    $response = $this->actingAs(ward(), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/start", [
            'cohort_override_reason' => 'Only HBV chair is out of service this morning.',
            'dialyzer_label_code' => $theirs->label_code,
        ]);

    $response->assertStatus(422);

    // The start never happened, so neither did the override. A note saying
    // "INFECTION CONTROL OVERRIDE" for a treatment that was refused is a chart
    // recording an event that did not occur.
    expect(DB::table('session_notes')->where('session_id', $session->id)->count())->toBe(0)
        ->and(DB::table('treatment_sessions')->where('id', $session->id)->value('status'))->toBe('checked_in');
});
