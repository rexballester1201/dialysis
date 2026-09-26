<?php

declare(strict_types=1);

use App\Domain\Clinical\Enums\Cohort;
use App\Domain\Clinical\Models\HdPrescription;
use App\Domain\Clinical\Models\TreatmentSession;
use App\Domain\Clinical\Services\CohortGuard;
use App\Domain\Clinical\Services\PrescriptionService;
use App\Domain\Clinical\Services\SessionLockService;
use App\Domain\Core\Models\Patient;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Clinical invariants
|--------------------------------------------------------------------------
| These are the rules where a regression hurts a patient or loses money.
| Run them on MySQL, never SQLite: SQLite ignores CHECK semantics we rely on,
| has no stored generated columns of the same shape, and no triggers here.
|
| phpunit.xml:  <env name="DB_CONNECTION" value="mysql"/>
|               <env name="DB_DATABASE"   value="dialysis_test"/>
*/

uses(TestCase::class, RefreshDatabase::class);

it('derives the infection-control cohort from the latest serology', function () {
    $patient = Patient::factory()->create();

    DB::table('serology_results')->insert([
        ['patient_id' => $patient->id, 'marker' => 'hbsag', 'result' => 'non_reactive', 'specimen_date' => '2025-01-10'],
        ['patient_id' => $patient->id, 'marker' => 'hbsag', 'result' => 'reactive',     'specimen_date' => '2026-01-15'],
    ]);

    expect($patient->cohort())->toBe(Cohort::Hbv);
});

it('refuses to seat an HBV patient in a clean chair', function () {
    $patient = Patient::factory()->hbvReactive()->create();
    $station = DB::table('stations')->where('code', 'S-01')->value('id');

    expect(app(CohortGuard::class)->violations($patient, $station, null))
        ->toHaveCount(1)
        ->and(app(CohortGuard::class)->violations($patient, $station, null)[0])
        ->toContain('not designated');
});

it('never allows two overlapping prescriptions', function () {
    $patient = Patient::factory()->create();

    HdPrescription::create([
        'patient_id' => $patient->id, 'version' => 1,
        'effective_from' => '2026-01-01', 'duration_min' => 240,
    ]);

    // The MySQL trigger is the backstop; the service should have closed v1 first.
    expect(fn () => HdPrescription::create([
        'patient_id' => $patient->id, 'version' => 2,
        'effective_from' => '2026-06-01', 'duration_min' => 210,
    ]))->toThrow(QueryException::class);
});

it('closes the previous version when a prescription is revised', function () {
    $patient = Patient::factory()->create();
    $physician = staffWithRole('nephrologist');
    $service = app(PrescriptionService::class);

    $service->revise($patient, ['duration_min' => 240], now()->subMonths(6), $physician, 'initial');
    $v2 = $service->revise($patient, ['duration_min' => 270], now(), $physician, 'inadequate Kt/V');

    expect($v2->version)->toBe(2)
        ->and($patient->prescriptions()->whereNull('effective_to')->count())->toBe(1)
        ->and($patient->prescriptionOn(now())->id)->toBe($v2->id);
});

it('locks a session once both signatures are in', function () {
    $session = TreatmentSession::factory()->completed()->create();
    $service = app(SessionLockService::class);

    $service->signAsNurse($session, staffWithRole('nurse'));
    expect($session->fresh()->isLocked())->toBeFalse();

    $service->signAsPhysician($session, staffWithRole('nephrologist'));
    expect($session->fresh()->isLocked())->toBeTrue();
});

it('rejects a clinical edit to a locked session', function () {
    $session = TreatmentSession::factory()->locked()->create();

    expect(fn () => $session->forceFill(['ktv' => 1.9])->save())
        ->toThrow(QueryException::class);
});

it('still accepts the billing link on a locked session', function () {
    $session = TreatmentSession::factory()->locked()->create();
    $claimId = DB::table('claims')->insertGetId(claimAttributes($session));

    $session->forceFill(['benefit_claim_id' => $claimId])->save();

    expect($session->fresh()->benefit_claim_id)->toBe($claimId);
});

it('records an amendment instead of silently overwriting', function () {
    $session = TreatmentSession::factory()->locked()->create(['ktv' => 1.42]);
    $physician = staffWithRole('nephrologist');

    app(SessionLockService::class)->amend($session, ['ktv' => 1.45], 'lab recalculated', $physician);

    expect($session->fresh()->ktv)->toEqual('1.45')
        ->and($session->notes()->where('body', 'like', 'AMENDMENT%')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'UPDATE')
            ->where('auditable_type', TreatmentSession::class)->count())->toBeGreaterThan(0);
});

it('cannot bill the same session twice', function () {
    $session = TreatmentSession::factory()->locked()->create();
    $first = DB::table('claims')->insertGetId(claimAttributes($session));
    $second = DB::table('claims')->insertGetId(claimAttributes($session));

    DB::table('claim_sessions')->insert(['session_id' => $session->id, 'claim_id' => $first, 'amount' => 6350]);

    expect(fn () => DB::table('claim_sessions')
        ->insert(['session_id' => $session->id, 'claim_id' => $second, 'amount' => 6350]))
        ->toThrow(QueryException::class);
});

it('cannot double-book a chair in the same shift', function () {
    $existing = TreatmentSession::factory()->create();

    expect(fn () => TreatmentSession::factory()->create([
        'session_date' => $existing->session_date,
        'shift_id' => $existing->shift_id,
        'station_id' => $existing->station_id,
    ]))->toThrow(QueryException::class);
});

it('requires a witness for high-alert medication', function () {
    $session = TreatmentSession::factory()->create();
    $heparin = DB::table('medication_refs')->where('is_high_alert', 1)->value('id');

    expect(fn () => DB::table('medication_administrations')->insert([
        'session_id' => $session->id, 'patient_id' => $session->patient_id,
        'medication_id' => $heparin, 'dose' => 2000, 'dose_unit' => 'IU',
        'route' => 'IV', 'administered_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('does not inflate session counts in the monthly rollup', function () {
    // Regression guard: joining session_events without LATERAL multiplied
    // each session row by its event count.
    $session = TreatmentSession::factory()->completed()->create();
    DB::table('session_events')->insert([
        ['session_id' => $session->id, 'occurred_at' => now(), 'event_code' => 'hypotension', 'severity' => 'moderate'],
        ['session_id' => $session->id, 'occurred_at' => now(), 'event_code' => 'cramps',      'severity' => 'minor'],
    ]);

    $row = DB::table('v_monthly_quality')->where('patient_id', $session->patient_id)->first();

    expect((int) $row->sessions)->toBe(1)
        ->and((int) $row->sessions_with_hypotension)->toBe(1);
});

it('reports benefit utilisation against the annual cap', function () {
    $session = TreatmentSession::factory()->locked()->create();
    seedPhilHealthBenefit($session);

    $row = DB::table('v_benefit_utilisation')->where('patient_id', $session->patient_id)->first();

    expect((int) $row->sessions_allotted)->toBe(156)
        ->and((int) $row->sessions_claimed)->toBe(1)
        ->and((int) $row->sessions_remaining)->toBe(155);
});
