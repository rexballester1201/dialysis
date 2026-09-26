<?php

declare(strict_types=1);

use App\Domain\Clinical\Models\TreatmentSession;
use App\Domain\Core\Models\Patient;
use App\Domain\Core\Models\Staff;
use App\Domain\Ops\Models\DialyzerUnit;
use App\Domain\Ops\Services\DialyzerReuseService;
use App\Domain\Ops\Services\WaterComplianceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Invariants 8 and 9
|--------------------------------------------------------------------------
| These are the two rules CLAUDE.md lists with no test beside them, and until
| now nothing wrote the data their detective views read. Both are enforced at
| the moment they can still prevent something:
|
|   9  total chlorine over 0.1 ppm blocks the day's FIRST session, because
|      chlorine crossing a dialyzer membrane haemolyses the patient
|   8  a dialyzer below 80% of its original total cell volume, past its reuse
|      count, or belonging to someone else is refused at issue
|
| The views stay as the backstop for anything that goes round the service.
*/

uses(TestCase::class, RefreshDatabase::class);

function technician(): Staff
{
    return staffWithRole('technician');
}

/** A session checked in and ready for the needle. */
function readyToStart(): TreatmentSession
{
    $patient = Patient::factory()->create();

    $session = TreatmentSession::factory()->create([
        'patient_id' => $patient->id,
        'status' => 'checked_in',
        'session_date' => now()->toDateString(),
        'checked_in_at' => now()->subMinutes(10),
        'pre_weight_kg' => '60.40',
        'dry_weight_kg' => '57.50',
    ]);

    return $session;
}

/* -------------------------------------------------------------------------- */
/* Invariant 9 -- water */
/* -------------------------------------------------------------------------- */

it('refuses to start the first treatment of the day with no water check logged', function () {
    $session = readyToStart();

    $response = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/start", []);

    $response->assertStatus(422);

    // Silence is not clearance. An unmeasured carbon bed is not a safe one.
    expect($response->json('message'))->toContain('No water check')
        ->and($session->fresh()->started_at)->toBeNull();
});

it('refuses to start when total chlorine is over the action limit', function () {
    $session = readyToStart();

    // 0.15 ppm, above the 0.1 ppm AAMI action limit the schema also encodes.
    passingWaterCheck(now(), 0.15);

    $response = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/start", []);

    $response->assertStatus(422);

    expect($response->json('message'))->toContain('0.15')
        ->and($response->json('message'))->toContain('carbon beds')
        ->and($session->fresh()->started_at)->toBeNull();
});

it('starts once a passing water check is on record', function () {
    $session = readyToStart();

    passingWaterCheck(now(), 0.02);

    $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/start", [])
        ->assertOk()
        ->assertJsonPath('status', 'in_progress');
});

it('gates only the first treatment of the day, not every needle', function () {
    passingWaterCheck(now(), 0.02);

    $first = readyToStart();
    $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/sessions/{$first->public_id}/start", [])->assertOk();

    // A mid-shift re-test that breaches -- the latest reading the gate would
    // refuse. The morning check passed and treatment is under way, so the unit's
    // policy is not to re-apply the gate to every needle; the second start
    // succeeding is what proves the gate was not re-applied.
    //
    // This test used to prove the same thing by deleting every water log for
    // the day. That produced the state "no water check exists today" and
    // asserted a patient could still be started in it -- which is the fail-open
    // hadPassingCheckOn() now closes. Water logs are compliance records and are
    // never deleted in practice; a breaching re-test is the realistic case.
    DB::table('water_daily_logs')->insert([
        'water_system_id' => DB::table('water_systems')->value('id'),
        'logged_at' => now()->copy()->setTime(23, 0),
        'total_chlorine_ppm' => 0.25,
        'is_out_of_range' => 1,
    ]);

    $second = readyToStart();
    $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/sessions/{$second->public_id}/start", [])->assertOk();
});

it('does not take a session that bypassed the gate as proof the water was tested', function () {
    // No water check logged today. A session reaches in_progress without coming
    // through start() -- an import, a console command, a direct insert, or the
    // offline upsert that once accepted status and started_at.
    TreatmentSession::factory()->create([
        'session_date' => now()->toDateString(),
        'status' => 'in_progress',
        'started_at' => now()->subHour(),
    ]);

    $next = readyToStart();
    $response = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/sessions/{$next->public_id}/start", []);

    // The old gate returned early because "a treatment has started today" and
    // waved this through -- one bypass switched the water check off for the
    // whole unit for the rest of the day.
    $response->assertStatus(422);
    expect($response->json('message'))->toContain('No water check has been logged')
        ->and(DB::table('treatment_sessions')->where('id', $next->id)->value('status'))->toBe('checked_in');
});

it('flags a breaching reading and reports the clearance in one round trip', function () {
    $systemId = DB::table('water_systems')->value('id');

    $response = $this->actingAs(technician(), 'sanctum')
        ->postJson('/api/v1/water-logs', [
            'water_system_id' => $systemId,
            'total_chlorine_ppm' => 0.35,
            'carbon_tank_ok' => false,
            'action_taken' => 'carbon beds changed, retest pending',
        ]);

    $response->assertCreated()
        ->assertJsonPath('is_out_of_range', true)
        ->assertJsonPath('action_limit_ppm', WaterComplianceService::TOTAL_CHLORINE_LIMIT_PPM)
        ->assertJsonPath('clearance.cleared', false);

    // A breach is an answer, not an error to retry.
    expect(DB::table('water_daily_logs')->where('is_out_of_range', 1)->count())->toBe(1);
});

it('surfaces a breach through the detective view as well', function () {
    $systemId = DB::table('water_systems')->value('id');

    // A breach now has to say what was done about it -- the request always
    // documented that, and now enforces it. This test is about the detective
    // view, so it simply says.
    $this->actingAs(technician(), 'sanctum')
        ->postJson('/api/v1/water-logs', [
            'water_system_id' => $systemId,
            'total_chlorine_ppm' => 0.35,
            'action_taken' => 'Carbon tank exhausted; unit stopped and tank being changed.',
        ])->assertCreated();

    // v_water_exceptions is the backstop for anything that bypasses the service.
    expect(DB::table('v_water_exceptions')->count())->toBe(1);

    $this->artisan('dialysis:check-controls')
        ->expectsOutputToContain('WATER (invariant 9)')
        ->assertExitCode(1);
});

it('will not accept a water check with no chlorine reading', function () {
    $this->actingAs(technician(), 'sanctum')
        ->postJson('/api/v1/water-logs', [
            'water_system_id' => DB::table('water_systems')->value('id'),
            'ph' => 7.1,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('total_chlorine_ppm');
});

/* -------------------------------------------------------------------------- */
/* Invariant 8 -- dialyzer reuse */
/* -------------------------------------------------------------------------- */

it('refuses a dialyzer below 80% of its original total cell volume', function () {
    passingWaterCheck();
    $session = readyToStart();

    // 82 of 110 mL = 74.5%, under the 80% floor.
    $unit = dialyzerFor($session->patient, ['current_tcv_ml' => '82.0', 'use_count' => 3]);

    $response = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/start", [
            'dialyzer_unit_id' => $unit->id,
        ]);

    $response->assertStatus(422);

    expect($response->json('message'))->toContain('total cell volume')
        ->and($response->json('message'))->toContain($unit->label_code)
        ->and($session->fresh()->started_at)->toBeNull();
});

it('refuses a dialyzer that has reached its reuse count', function () {
    passingWaterCheck();
    $session = readyToStart();

    $maxReuse = (int) DB::table('items')->where('is_reusable', 1)->value('max_reuse_count');
    $unit = dialyzerFor($session->patient, ['use_count' => $maxReuse]);

    $response = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/start", [
            'dialyzer_unit_id' => $unit->id,
        ]);

    $response->assertStatus(422);

    expect($response->json('message'))->toContain('limit is '.$maxReuse)
        ->and($session->fresh()->started_at)->toBeNull();
});

it('never issues one patient a dialyzer belonging to another', function () {
    passingWaterCheck();
    $session = readyToStart();

    $someoneElse = Patient::factory()->create(['mrn' => 'MRN-55555']);
    $theirUnit = dialyzerFor($someoneElse);

    $response = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/start", [
            'dialyzer_unit_id' => $theirUnit->id,
        ]);

    // A dialyzer carries the previous patient's blood proteins. This is a
    // cross-infection event, not a paperwork slip.
    $response->assertStatus(422);

    expect($response->json('message'))->toContain('MRN-55555')
        ->and($response->json('message'))->toContain('never')
        ->and($session->fresh()->started_at)->toBeNull();
});

it('issues a dialyzer that is within volume and count', function () {
    passingWaterCheck();
    $session = readyToStart();

    $unit = dialyzerFor($session->patient, ['current_tcv_ml' => '104.0', 'use_count' => 2]);

    $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/start", [
            'dialyzer_unit_id' => $unit->id,
        ])
        ->assertOk()
        ->assertJsonPath('status', 'in_progress');

    // 104/110 = 94.55%, comfortably above the floor.
    expect((float) $unit->fresh()->tcv_pct)->toBeGreaterThan(DialyzerReuseService::MIN_TCV_PCT)
        ->and($unit->fresh()->first_used_on)->not->toBeNull();
});

it('condemns a dialyzer at reprocessing when its volume drops below the floor', function () {
    $patient = Patient::factory()->create();
    $unit = dialyzerFor($patient, ['use_count' => 2]);

    $response = $this->actingAs(technician(), 'sanctum')
        ->postJson("/api/v1/dialyzers/{$unit->id}/reprocess", [
            // 85 of 110 mL = 77.3%.
            'tcv_ml' => 85.0,
            'method' => 'automated',
            'germicide' => 'Renalin',
            'accepted' => true,
        ]);

    $response->assertCreated()
        ->assertJsonPath('status', 'discarded')
        ->assertJsonPath('issuable_again', false);

    expect($response->json('discard_reason'))->toContain('below the 80')
        ->and($unit->fresh()->status)->toBe('discarded');
});

it('condemns a dialyzer that fails the technician s inspection regardless of volume', function () {
    $patient = Patient::factory()->create();
    $unit = dialyzerFor($patient, ['use_count' => 1]);

    // Volume is fine; the fibre bundle is not. `accepted` is the technician's
    // verdict and it is final.
    $this->actingAs(technician(), 'sanctum')
        ->postJson("/api/v1/dialyzers/{$unit->id}/reprocess", [
            'tcv_ml' => 108.0,
            'fibre_bundle_ok' => false,
            'accepted' => false,
            'reject_reason' => 'clotted fibre bundle on visual inspection',
        ])
        ->assertCreated()
        ->assertJsonPath('status', 'discarded');

    expect($unit->fresh()->discard_reason)->toContain('clotted fibre bundle');
});

it('keeps a dialyzer in service when it passes reprocessing', function () {
    $patient = Patient::factory()->create();
    $unit = dialyzerFor($patient, ['use_count' => 1]);

    $this->actingAs(technician(), 'sanctum')
        ->postJson("/api/v1/dialyzers/{$unit->id}/reprocess", [
            'tcv_ml' => 106.0,
            'method' => 'automated',
            'pressure_test_passed' => true,
            'fibre_bundle_ok' => true,
            'visual_ok' => true,
            'residual_test_passed' => true,
            'accepted' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('use_count', 2)
        ->assertJsonPath('issuable_again', true);
});

it('requires the measured volume at reprocessing', function () {
    $unit = dialyzerFor(Patient::factory()->create());

    // Reprocessing without measuring is exactly the failure this record exists
    // to prevent.
    $this->actingAs(technician(), 'sanctum')
        ->postJson("/api/v1/dialyzers/{$unit->id}/reprocess", ['accepted' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors('tcv_ml');
});

it('records every cycle against the unit for an incident review', function () {
    $patient = Patient::factory()->create();
    $unit = dialyzerFor($patient);
    $tech = technician();

    foreach ([108.0, 106.0, 104.0] as $volume) {
        $this->actingAs($tech, 'sanctum')
            ->postJson("/api/v1/dialyzers/{$unit->id}/reprocess", [
                'tcv_ml' => $volume, 'accepted' => true, 'method' => 'automated',
            ])->assertCreated();
    }

    $history = $this->actingAs($tech, 'sanctum')
        ->getJson("/api/v1/dialyzers/{$unit->id}/history")
        ->assertOk()
        ->json();

    expect($history['cycles'])->toHaveCount(3)
        ->and($history['cycles'][0]['use_number'])->toBe(1)
        ->and($history['cycles'][2]['use_number'])->toBe(3)
        // "What was this dialyzer's volume last time it went on a patient?"
        ->and((float) $history['cycles'][2]['tcv_ml'])->toBe(104.0);
});

it('surfaces a condemned-but-still-active unit through the detective view', function () {
    $patient = Patient::factory()->create();

    // Written straight to the table, as a stray script or console command would:
    // below the floor but never condemned. This is what the view is for.
    DialyzerUnit::create([
        'item_id' => DB::table('items')->where('is_reusable', 1)->value('id'),
        'patient_id' => $patient->id,
        'label_code' => 'DZ-STRAY',
        'use_count' => 4,
        'initial_tcv_ml' => '110.0',
        'current_tcv_ml' => '80.0', // 72.7%
        'status' => 'active',
    ]);

    expect(DB::table('v_dialyzer_status')->where('flag', 'discard_tcv')->count())->toBe(1);

    $this->artisan('dialysis:check-controls')
        ->expectsOutputToContain('DIALYZER (invariant 8)')
        ->assertExitCode(1);
});

it('lists a patient s dialyzers with the reason any is unusable', function () {
    $patient = Patient::factory()->create();

    dialyzerFor($patient, ['label_code' => 'DZ-GOOD', 'current_tcv_ml' => '105.0']);
    dialyzerFor($patient, ['label_code' => 'DZ-WORN', 'current_tcv_ml' => '80.0']);

    $body = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->getJson("/api/v1/patients/{$patient->public_id}/dialyzers")
        ->assertOk()
        ->json();

    $byLabel = collect($body['units'])->keyBy('label_code');

    expect((float) $body['minimum_tcv_pct'])->toBe(DialyzerReuseService::MIN_TCV_PCT)
        ->and($byLabel['DZ-GOOD']['refusal_reason'])->toBeNull()
        ->and($byLabel['DZ-WORN']['refusal_reason'])->toContain('total cell volume');
});

/* -------------------------------------------------------------------------- */
/* The unit's day, not the UTC calendar */
/* -------------------------------------------------------------------------- */

/** A passing check, recorded through the endpoint the Water screen uses. */
function recordWaterCheck(array $overrides = []): TestResponse
{
    return test()->actingAs(technician(), 'sanctum')->postJson('/api/v1/water-logs', $overrides + [
        'water_system_id' => DB::table('water_systems')->value('id'),
        'total_chlorine_ppm' => 0.02,
    ]);
}

it('counts a 05:30 check for the unit s own day, so the 06:00 shift can start', function () {
    unitIn('Asia/Manila');

    // 05:30 in Manila on 11 March is 21:30 UTC on the 10th. Against the UTC
    // calendar this check was "yesterday's", and the 06:00 start was refused.
    $this->travelTo(Carbon\Carbon::parse('2030-03-11 05:30:00', 'Asia/Manila'));

    recordWaterCheck()
        ->assertCreated()
        ->assertJsonPath('date', '2030-03-11')
        ->assertJsonPath('clearance.cleared', true);

    $this->travelTo(Carbon\Carbon::parse('2030-03-11 06:00:00', 'Asia/Manila'));
    $water = app(WaterComplianceService::class);

    // The 06:00 session is on the board for the 11th. No exception is the pass.
    $water->assertClearedToStart(Carbon\Carbon::parse('2030-03-11'));

    expect($water->clearanceFor(Carbon\Carbon::parse('2030-03-10'))['cleared'])->toBeFalse();
});

it('closes the unit s day at its own midnight, not at 08:00', function () {
    unitIn('Asia/Manila');
    $water = app(WaterComplianceService::class);

    // Late on the 11th, a pass. Half an hour after midnight, a breach.
    $this->travelTo(Carbon\Carbon::parse('2030-03-11 23:30:00', 'Asia/Manila'));
    recordWaterCheck()->assertCreated()->assertJsonPath('date', '2030-03-11');

    $this->travelTo(Carbon\Carbon::parse('2030-03-12 00:30:00', 'Asia/Manila'));
    recordWaterCheck([
        'total_chlorine_ppm' => 0.4,
        'action_taken' => 'Carbon tank exhausted; unit stopped.',
    ])->assertCreated()->assertJsonPath('date', '2030-03-12');

    // Both are on UTC's 11 March. Counted by UTC, the breach became the 11th's
    // latest reading and failed a day that had passed; it belongs to the 12th.
    expect($water->clearanceFor(Carbon\Carbon::parse('2030-03-11'))['cleared'])->toBeTrue()
        ->and($water->clearanceFor(Carbon\Carbon::parse('2030-03-12'))['cleared'])->toBeFalse();
});

it('lists the unit s day on the Water screen, starting from the unit s today', function () {
    unitIn('Asia/Manila');
    $this->travelTo(Carbon\Carbon::parse('2030-03-11 05:30:00', 'Asia/Manila'));

    recordWaterCheck(['shift_code' => 'AM', 'ph' => 7.2])->assertCreated();

    $body = $this->actingAs(technician(), 'sanctum')->getJson('/api/v1/water-logs')->assertOk()->json();

    expect($body['date'])->toBe('2030-03-11')
        ->and($body['today'])->toBe('2030-03-11')
        ->and($body['timezone'])->toBe('Asia/Manila')
        ->and($body['clearance']['cleared'])->toBeTrue()
        ->and($body['logs'])->toHaveCount(1)
        ->and($body['logs'][0]['shift_code'])->toBe('AM')
        ->and($body['logs'][0]['logged_by'])->not->toBeNull()
        // ISO with its offset, never MySQL's zoneless text.
        ->and($body['logs'][0]['logged_at'])->toBe('2030-03-10T21:30:00+00:00')
        ->and($body['logs'][0])->not->toHaveKey('created_at')
        ->and($body['systems'])->not->toBeEmpty()
        ->and(array_column($body['shifts'], 'code'))->toContain('AM')
        ->and($body['can_record'])->toBeTrue();

    // A nurse reads the day but is not the one who records it.
    $this->actingAs(staffWithRole('nurse'), 'sanctum')->getJson('/api/v1/water-logs')
        ->assertOk()
        ->assertJsonPath('can_record', false);
});

it('stores a stated check time in UTC, whatever zone it came in', function () {
    $this->travelTo(Carbon\Carbon::parse('2030-03-11 06:00:00', 'Asia/Manila'));

    recordWaterCheck(['logged_at' => '2030-03-11T05:30:00+08:00'])->assertCreated();

    // Eloquent alone would have stored the wall clock -- 05:30 -- eight hours out.
    expect((string) DB::table('water_daily_logs')->value('logged_at'))->toStartWith('2030-03-10 21:30:00');
});

it('refuses a check time with no zone on it', function () {
    recordWaterCheck(['logged_at' => '2030-03-11 05:30:00'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('logged_at');

    expect(DB::table('water_daily_logs')->count())->toBe(0);
});

it('refuses a check timed in the future', function () {
    // The gate believes the latest check of the day, so a passing reading dated
    // ahead would hide a failing one recorded before it.
    $response = recordWaterCheck(['logged_at' => now()->addHour()->toIso8601String()]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('has not happened yet')
        ->and(DB::table('water_daily_logs')->count())->toBe(0);
});

it('demands what was done when a reading breaches the action limit', function () {
    recordWaterCheck(['total_chlorine_ppm' => 0.35])
        ->assertStatus(422)
        ->assertJsonValidationErrors('action_taken');

    recordWaterCheck(['total_chlorine_ppm' => 0.35, 'action_taken' => 'Carbon tank exhausted; unit stopped.'])
        ->assertCreated()
        ->assertJsonPath('is_out_of_range', true)
        ->assertJsonPath('clearance.cleared', false);

    // At the limit is not above it (v_water_exceptions uses `>`), so nothing
    // needs explaining.
    recordWaterCheck(['total_chlorine_ppm' => 0.1])->assertCreated()->assertJsonPath('is_out_of_range', false);
});

it('refuses a reading more precise than the column keeps', function () {
    // DECIMAL(6,3) would round 0.1004 to 0.100: flagged as a breach on entry,
    // then read back by the gate as a pass.
    recordWaterCheck(['total_chlorine_ppm' => 0.1004, 'action_taken' => 'n/a'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('total_chlorine_ppm');
});

it('takes the shift by its code, and refuses one that does not exist', function () {
    recordWaterCheck(['shift_code' => 'AM'])->assertCreated();

    expect((int) DB::table('water_daily_logs')->value('shift_id'))->toBe((int) DB::table('shifts')->where('code', 'AM')->value('id'));

    recordWaterCheck(['shift_code' => 'DAWN'])->assertStatus(422)->assertJsonValidationErrors('shift_code');
});

it('writes the time a check was entered in UTC, not the database server s clock', function () {
    $this->travelTo(Carbon\Carbon::parse('2030-03-11 05:30:00', 'Asia/Manila'));

    recordWaterCheck()->assertCreated();

    // created_at defaults to CURRENT_TIMESTAMP, which is the MySQL server's zone.
    expect((string) DB::table('water_daily_logs')->value('created_at'))->toStartWith('2030-03-10 21:30:00');
});

it('tells the Water screen when a failing reading will not stop the next start', function () {
    // Before any treatment, a breach blocks the day's first start.
    recordWaterCheck(['total_chlorine_ppm' => 0.3, 'action_taken' => 'Carbon tank being changed.'])
        ->assertCreated()
        ->assertJsonPath('gate.treatment_started', false)
        ->assertJsonPath('gate.start_allowed', false);

    // A passing re-test clears it, and the first treatment goes ahead.
    recordWaterCheck()->assertCreated()->assertJsonPath('gate.start_allowed', true);
    TreatmentSession::factory()->inProgress()->create(['session_date' => now()->toDateString()]);

    // Mid-shift the carbon breaks through. The latest reading fails, but the
    // unit's "not every needle" policy lets the next start through -- and the
    // screen must say so, not promise a refusal that will not happen.
    $body = recordWaterCheck(['total_chlorine_ppm' => 0.3, 'action_taken' => 'Breakthrough; unit stopped.'])
        ->assertCreated()
        ->json();

    expect($body['clearance']['cleared'])->toBeFalse()
        ->and($body['gate']['treatment_started'])->toBeTrue()
        ->and($body['gate']['start_allowed'])->toBeTrue();

    // Which is exactly what the enforced gate does. No exception is the pass.
    app(WaterComplianceService::class)->assertClearedToStart(now());
});
