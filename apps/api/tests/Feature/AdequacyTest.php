<?php

declare(strict_types=1);

use App\Domain\Clinical\Adequacy;
use App\Domain\Clinical\Models\TreatmentSession;
use App\Domain\Core\Models\Patient;
use App\Support\Exceptions\DomainRuleException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Dialysis adequacy
|--------------------------------------------------------------------------
| Kt/V and URR are derived values, and the server derives them rather than
| storing whatever a client sends. A chart holding a Kt/V inconsistent with its
| own BUN samples is a chart that will be believed and is wrong -- and Kt/V is
| what decides whether a prescription gets lengthened.
|
| The worked example below is pinned identically here and in
| packages/domain/src/index.ts, so the tablet and the server cannot drift.
|
|   pre-BUN 60, post-BUN 20, 4.0 h, 3.0 L removed, 70 kg post weight
|   R    = 20/60           = 0.33333
|   Kt/V = -ln(0.33333 - 0.008 x 4) + (4 - 3.5 x 0.33333) x (3.0/70)
|        = -ln(0.30133) + 2.83333 x 0.042857
|        = 1.19955 + 0.12143  = 1.32
|   URR  = (60-20)/60 x 100   = 66.7%
*/

uses(TestCase::class, RefreshDatabase::class);

it('computes the Daugirdas worked example the same way the tablet does', function () {
    expect(Adequacy::ktv(preBun: 60, postBun: 20, hours: 4.0, ufLitres: 3.0, postWeightKg: 70))->toBe(1.32)
        ->and(Adequacy::urrPct(60, 20))->toBe(66.7);
});

it('knows the published targets', function () {
    // KDOQI 2015: Kt/V >= 1.2, URR >= 65%.
    expect(Adequacy::KTV_TARGET)->toBe(1.2)
        ->and(Adequacy::URR_TARGET_PCT)->toBe(65.0)
        ->and(Adequacy::ktvMeetsTarget(1.2))->toBeTrue()
        ->and(Adequacy::ktvMeetsTarget(1.19))->toBeFalse()
        ->and(Adequacy::urrMeetsTarget(65.0))->toBeTrue()
        ->and(Adequacy::urrMeetsTarget(64.9))->toBeFalse();
});

it('computes the UF rate and flags the Flythe threshold', function () {
    // 3000 mL over 4 h at 60 kg = 12.5 mL/kg/h, under the limit.
    expect(Adequacy::ufRateMlPerKgPerHour(3000, 60, 4))->toBe(12.5)
        ->and(Adequacy::ufRateIsOfConcern(12.5))->toBeFalse()
        // 3400 mL over the same session = 14.2, above it.
        ->and(Adequacy::ufRateMlPerKgPerHour(3400, 60, 4))->toBe(14.2)
        ->and(Adequacy::ufRateIsOfConcern(14.2))->toBeTrue()
        ->and(Adequacy::UF_RATE_CONCERN_ML_KG_H)->toBe(13.0);
});

it('refuses to invent a number from impossible inputs', function () {
    // Samples the wrong way round.
    expect(fn () => Adequacy::ktv(preBun: 20, postBun: 60, hours: 4, ufLitres: 3, postWeightKg: 70))
        ->toThrow(DomainRuleException::class);

    // A zero-length session.
    expect(fn () => Adequacy::ktv(preBun: 60, postBun: 20, hours: 0, ufLitres: 3, postWeightKg: 70))
        ->toThrow(DomainRuleException::class);

    // A pre-BUN of zero would divide by zero in URR.
    expect(fn () => Adequacy::urrPct(0, 0))->toThrow(DomainRuleException::class);

    // ln of a non-positive number: physically impossible, so it refuses rather
    // than returning NAN.
    expect(fn () => Adequacy::ktv(preBun: 100, postBun: 1, hours: 8, ufLitres: 2, postWeightKg: 70))
        ->toThrow(DomainRuleException::class);
});

/* -------------------------------------------------------------------------- */
/* Server-side derivation */
/* -------------------------------------------------------------------------- */

/** A session ready to be ended, four hours in, with a water check on record. */
function runningSession(): TreatmentSession
{
    passingWaterCheck();

    $patient = Patient::factory()->create();

    return TreatmentSession::factory()->create([
        'patient_id' => $patient->id,
        'status' => 'in_progress',
        'session_date' => now()->toDateString(),
        'checked_in_at' => now()->subMinutes(250),
        'started_at' => now()->subMinutes(240),
        'pre_weight_kg' => '73.00',
        'dry_weight_kg' => '70.00',
    ]);
}

it('recomputes Kt/V from the BUN samples and overrides what the client sent', function () {
    $session = runningSession();

    $body = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/end", [
            'ended_at' => now()->toDateTimeString(),
            'post_weight_kg' => '70.00',
            'net_uf_ml' => 3000,
            'pre_bun_mmol' => 60,
            'post_bun_mmol' => 20,
            // The client claims a flattering number. It is not what gets stored.
            'ktv' => 1.9,
            'urr_pct' => 95,
        ])
        ->assertOk()
        ->json();

    expect((float) $body['ktv'])->toBe(1.32)
        ->and((float) $body['urr_pct'])->toBe(66.7)
        ->and($body['adequacy']['ktv_meets_target'])->toBeTrue()
        ->and($body['adequacy']['urr_meets_target'])->toBeTrue();
});

it('reports a session that did not reach target rather than massaging it', function () {
    $session = runningSession();

    $body = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/end", [
            'ended_at' => now()->toDateTimeString(),
            'post_weight_kg' => '70.00',
            'net_uf_ml' => 3000,
            // A poor clearance: 60 -> 34.
            'pre_bun_mmol' => 60,
            'post_bun_mmol' => 34,
        ])
        ->assertOk()
        ->json();

    expect((float) $body['urr_pct'])->toBe(43.3)
        ->and($body['adequacy']['urr_meets_target'])->toBeFalse()
        ->and($body['adequacy']['ktv_meets_target'])->toBeFalse();
});

it('leaves an online-clearance Kt/V alone, because that one is measured', function () {
    $session = runningSession();

    // Kt/V from the machine's dialysance sensor is a reading, not a derivation
    // from BUN. Recomputing over it would replace a measurement with an estimate.
    $body = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/end", [
            'ended_at' => now()->toDateTimeString(),
            'post_weight_kg' => '70.00',
            'net_uf_ml' => 3000,
            'pre_bun_mmol' => 60,
            'post_bun_mmol' => 20,
            'ktv' => 1.45,
            'ktv_method' => 'online_clearance',
        ])
        ->assertOk()
        ->json();

    expect((float) $body['ktv'])->toBe(1.45)
        // URR is a pure ratio with no method variance, so it is still derived.
        ->and((float) $body['urr_pct'])->toBe(66.7);
});

it('does not fabricate adequacy when the bloods were never drawn', function () {
    $session = runningSession();

    $body = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/end", [
            'ended_at' => now()->toDateTimeString(),
            'post_weight_kg' => '70.00',
            'net_uf_ml' => 3000,
        ])
        ->assertOk()
        ->json();

    // No BUN samples, no Kt/V. A missing number is honest; an invented one is not.
    expect($body['ktv'])->toBeNull()
        ->and($body['urr_pct'])->toBeNull()
        ->and($body['adequacy']['ktv_meets_target'])->toBeNull()
        ->and($body['adequacy']['urr_meets_target'])->toBeNull();
});

it('flags a fluid removal rate above the mortality threshold', function () {
    $session = runningSession();

    $body = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/end", [
            'ended_at' => now()->toDateTimeString(),
            'post_weight_kg' => '70.00',
            // 3800 mL over 4 h at a 70 kg dry weight = 13.6 mL/kg/h.
            'net_uf_ml' => 3800,
        ])
        ->assertOk()
        ->json();

    expect($body['adequacy']['uf_rate_ml_kg_h'])->toBe(13.6)
        ->and($body['adequacy']['uf_rate_of_concern'])->toBeTrue()
        // Reported, not refused: the fluid had to come off. The answer is a
        // longer session, which is a prescribing decision.
        ->and($body['status'])->toBe('completed');
});

it('carries the derived adequacy into the monthly quality view', function () {
    $session = runningSession();

    $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/end", [
            'ended_at' => now()->toDateTimeString(),
            'post_weight_kg' => '70.00',
            'net_uf_ml' => 3000,
            'pre_bun_mmol' => 60,
            'post_bun_mmol' => 20,
        ])->assertOk();

    // v_monthly_quality is the definition of record for adequacy reporting, and
    // it now reads a number the server computed rather than one a tablet sent.
    $row = DB::table('v_monthly_quality')->where('patient_id', $session->patient_id)->first();

    expect((float) $row->avg_ktv)->toBe(1.32)
        ->and((int) $row->sessions)->toBe(1);
});
