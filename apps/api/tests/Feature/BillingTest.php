<?php

declare(strict_types=1);

use App\Domain\Billing\Services\BenefitLedger;
use App\Domain\Clinical\Models\TreatmentSession;
use App\Domain\Core\Models\Patient;
use App\Domain\Core\Models\Staff;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Billing
|--------------------------------------------------------------------------
| Invariant 6: a session is billed at most once. BenefitLedger refuses the
| duplicate and names the claim that already has it; claim_sessions' primary key
| stops one that arrives any other way.
|
| No rate and no cap appear anywhere in this file either. The PhilHealth case
| rate has gone 2,600 -> 4,000 -> 6,350 and the annual allotment 90 -> 156, so a
| number written into a test is a number that will be wrong -- and the test would
| keep passing while the code billed the wrong amount.
*/

uses(TestCase::class, RefreshDatabase::class);

function biller(): Staff
{
    return staffWithRole('billing');
}

/** A locked, billable, completed session -- the only kind that may be claimed. */
function billableSession(Patient $patient, ?string $date = null): TreatmentSession
{
    return TreatmentSession::factory()->locked()->create([
        'patient_id' => $patient->id,
        'session_date' => $date ?? now()->toDateString(),
        'is_billable' => 1,
    ]);
}

/** The program in force today, read from the effective-dated rows. */
function currentProgram(): object
{
    return app(BenefitLedger::class)->programOn(now());
}

it('prices a claim from the benefit program in force, not from a constant', function () {
    $patient = Patient::factory()->create();
    $session = billableSession($patient);
    $program = currentProgram();

    $response = $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/claims", [
            'session_public_ids' => [$session->public_id],
        ]);

    $response->assertCreated()->assertJsonPath('session_count', 1);

    // Whatever the seeded circular says today is what the claim is worth.
    expect((float) $response->json('amount_claimed'))->toBe((float) $program->case_rate)
        ->and($response->json('utilisation.sessions_claimed'))->toBe(1)
        ->and($response->json('utilisation.sessions_allotted'))->toBe((int) $program->sessions_per_period);
});

it('refuses to bill the same session twice and names the claim that has it', function () {
    $patient = Patient::factory()->create();
    $session = billableSession($patient);
    $billing = biller();

    $first = $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/claims", ['session_public_ids' => [$session->public_id]])
        ->assertCreated();

    $second = $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/claims", ['session_public_ids' => [$session->public_id]]);

    $second->assertStatus(422);

    expect($second->json('message'))->toContain($first->json('claim_no'))
        // One claim, one claim_sessions row. The refusal wrote nothing.
        ->and(DB::table('claims')->count())->toBe(1)
        ->and(DB::table('claim_sessions')->count())->toBe(1);
});

it('still has the database backstop when the service is bypassed', function () {
    $patient = Patient::factory()->create();
    $session = billableSession($patient);

    $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/claims", ['session_public_ids' => [$session->public_id]])
        ->assertCreated();

    $otherClaim = DB::table('claims')->insertGetId(claimAttributes($session));

    // claim_sessions has session_id as its PRIMARY KEY. A stray script gets the
    // same answer the service gives, just less politely.
    expect(fn () => DB::table('claim_sessions')->insert([
        'session_id' => $session->id,
        'claim_id' => $otherClaim,
        'amount' => 1,
    ]))->toThrow(QueryException::class);
});

it('will not claim a session that has not been signed', function () {
    $patient = Patient::factory()->create();

    // Completed but never countersigned: work nobody has attested to yet.
    $unsigned = TreatmentSession::factory()->completed()->create([
        'patient_id' => $patient->id,
        'is_billable' => 1,
    ]);

    $response = $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/claims", ['session_public_ids' => [$unsigned->public_id]]);

    $response->assertStatus(422);

    expect($response->json('message'))->toContain('not signed')
        ->and(DB::table('claims')->count())->toBe(0);
});

it('will not claim a session marked not billable', function () {
    $patient = Patient::factory()->create();
    $session = billableSession($patient);

    // is_billable is one of the columns that stays writable on a locked record
    // (invariant 4), which is exactly how it gets set.
    $session->forceFill(['is_billable' => 0])->save();

    $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/claims", ['session_public_ids' => [$session->public_id]])
        ->assertStatus(422);

    expect(DB::table('claims')->count())->toBe(0);
});

it('never puts another patient s session on a claim', function () {
    $patient = Patient::factory()->create();
    $someoneElse = Patient::factory()->create();

    $mine = billableSession($patient);
    $theirs = billableSession($someoneElse);

    $response = $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/claims", [
            'session_public_ids' => [$mine->public_id, $theirs->public_id],
        ]);

    $response->assertStatus(422);

    expect($response->json('message'))->toContain('different patient')
        // All or nothing: the valid session was not claimed either.
        ->and(DB::table('claims')->count())->toBe(0)
        ->and(DB::table('claim_sessions')->count())->toBe(0);
});

it('refuses to exceed the annual allotment the program defines', function () {
    $patient = Patient::factory()->create();
    $program = currentProgram();
    $allotted = (int) $program->sessions_per_period;

    // Open the period and consume all but one of the allotment directly, so the
    // test does not depend on how large the cap happens to be this year.
    $period = DB::table('benefit_periods')->insertGetId([
        'patient_id' => $patient->id,
        'program_id' => $program->id,
        'period_start' => now()->startOfYear()->toDateString(),
        'period_end' => now()->endOfYear()->toDateString(),
        'sessions_allotted' => $allotted,
    ]);

    $claimId = DB::table('claims')->insertGetId([
        'claim_no' => 'CLM-PRIOR-0001',
        'patient_id' => $patient->id,
        'payer_id' => $program->payer_id,
        'program_id' => $program->id,
        'benefit_period_id' => $period,
        'service_from' => now()->startOfYear()->toDateString(),
        'service_to' => now()->toDateString(),
        'session_count' => $allotted - 1,
        'amount_claimed' => 0,
        'status' => 'submitted',
    ]);

    foreach (range(1, $allotted - 1) as $n) {
        $consumed = billableSession($patient, now()->subDays($n + 1)->toDateString());
        DB::table('claim_sessions')->insert([
            'session_id' => $consumed->id, 'claim_id' => $claimId, 'benefit_seq_no' => $n, 'amount' => 0,
        ]);
    }

    // One left. Two more sessions will not fit.
    $a = billableSession($patient, now()->toDateString());
    $b = billableSession($patient, now()->subDay()->toDateString());

    $response = $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/claims", ['session_public_ids' => [$a->public_id, $b->public_id]]);

    $response->assertStatus(422);

    expect($response->json('message'))->toContain('only 1 of the '.$allotted)
        ->and(DB::table('claims')->count())->toBe(1); // the prior one only
});

it('lists what is claimable without the already-claimed sessions', function () {
    $patient = Patient::factory()->create();
    $billing = biller();

    $claimed = billableSession($patient, now()->subDays(3)->toDateString());
    $open = billableSession($patient, now()->toDateString());

    $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/claims", ['session_public_ids' => [$claimed->public_id]])
        ->assertCreated();

    $body = $this->actingAs($billing, 'sanctum')
        ->getJson("/api/v1/patients/{$patient->public_id}/claimable")
        ->assertOk()
        ->json();

    expect($body['groups'])->toHaveCount(1)
        ->and($body['groups'][0]['sessions'])->toHaveCount(1)
        ->and($body['groups'][0]['sessions'][0]['public_id'])->toBe($open->public_id)
        // The rate quoted to the biller is the one that will actually be used.
        ->and((float) $body['groups'][0]['program']['case_rate'])->toBe((float) currentProgram()->case_rate)
        ->and($body['groups'][0]['program']['circular_ref'])->not->toBeNull()
        // The claimed session already counts against the period.
        ->and($body['groups'][0]['utilisation']['sessions_claimed'])->toBe(1);
});

it('never hands a session row id to the client, and will not take one back', function () {
    $patient = Patient::factory()->create();
    $session = billableSession($patient);
    $billing = biller();

    $body = $this->actingAs($billing, 'sanctum')
        ->getJson("/api/v1/patients/{$patient->public_id}/claimable")
        ->assertOk()
        ->json();

    // CLAUDE.md: `id` (BIGINT) never leaves the server. The list used to carry
    // it because generating a claim needed it back.
    expect($body['groups'][0]['sessions'][0])->not->toHaveKey('id');

    $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/claims", ['session_ids' => [$session->id]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('session_public_ids');

    expect(DB::table('claims')->count())->toBe(0);
});

it('lists a session no program covers, with the reason, instead of dropping it', function () {
    $patient = Patient::factory()->create();

    // The seeded programs leave a gap between the 2023 package (ends
    // 2024-07-01) and the current one (starts 2024-10-09).
    $orphan = billableSession($patient, '2024-08-15');

    $body = $this->actingAs(biller(), 'sanctum')
        ->getJson("/api/v1/patients/{$patient->public_id}/claimable")
        ->assertOk()
        ->json();

    expect($body['groups'])->toBe([])
        ->and($body['unclaimable'])->toHaveCount(1)
        ->and($body['unclaimable'][0]['public_id'])->toBe($orphan->public_id)
        ->and($body['unclaimable'][0]['reason'])->toContain('2024-08-15');
});

it('refuses a claim that spans two benefit periods', function () {
    $patient = Patient::factory()->create();
    $billing = biller();

    // Both under the current program, either side of a calendar-year boundary.
    // Neither date is ever in the future, so the listing below sees both
    // whatever day the suite runs.
    $december = billableSession($patient, now()->subYear()->endOfYear()->toDateString());
    $january = billableSession($patient, now()->startOfYear()->toDateString());

    $response = $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/claims", [
            'session_public_ids' => [$december->public_id, $january->public_id],
        ]);

    $response->assertStatus(422);

    // It used to succeed, spending January's treatment from December's year.
    expect($response->json('message'))->toContain('two benefit periods')
        ->and($response->json('message'))->toContain($january->public_id)
        ->and(DB::table('claims')->count())->toBe(0);

    // The listing already puts them in separate groups, so the screen never
    // offers the mixed claim to begin with.
    $groups = $this->actingAs($billing, 'sanctum')
        ->getJson("/api/v1/patients/{$patient->public_id}/claimable")
        ->assertOk()
        ->json('groups');

    expect($groups)->toHaveCount(2);
});

it('refuses a claim that mixes two benefit programs', function () {
    $patient = Patient::factory()->create();

    // Same calendar year, different programs: the 2023 package until
    // 2024-07-01, the current one from 2024-10-09.
    $old = billableSession($patient, '2024-06-10');
    $new = billableSession($patient, '2024-11-12');

    $response = $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/claims", [
            'session_public_ids' => [$old->public_id, $new->public_id],
        ]);

    $response->assertStatus(422);

    expect($response->json('message'))->toContain('mixes benefit programs')
        ->and($response->json('message'))->toContain($new->public_id)
        ->and(DB::table('claims')->count())->toBe(0);
});

it('numbers each session within the annual benefit', function () {
    $patient = Patient::factory()->create();
    $billing = biller();

    $one = billableSession($patient, now()->subDays(4)->toDateString());
    $two = billableSession($patient, now()->subDays(2)->toDateString());

    $claim = $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/claims", ['session_public_ids' => [$one->public_id, $two->public_id]])
        ->assertCreated()
        ->json('claim_no');

    $body = $this->actingAs($billing, 'sanctum')
        ->getJson("/api/v1/claims/{$claim}")
        ->assertOk()
        ->json();

    // "session 1 of 156, session 2 of 156" -- the sequence the payer quotes back.
    expect(array_column($body['sessions'], 'benefit_seq_no'))->toBe([1, 2]);
});

it('keeps the status history as a claim moves through the payer', function () {
    $patient = Patient::factory()->create();
    $session = billableSession($patient);
    $billing = biller();

    $claimNo = $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/claims", ['session_public_ids' => [$session->public_id]])
        ->assertCreated()
        ->json('claim_no');

    $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/status", ['status' => 'submitted'])
        ->assertOk()
        ->assertJsonPath('status', 'submitted');

    $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/status", [
            'status' => 'denied',
            'remarks' => 'missing attending physician signature on the transmittal',
        ])
        ->assertOk();

    $body = $this->actingAs($billing, 'sanctum')->getJson("/api/v1/claims/{$claimNo}")->assertOk()->json();

    expect(array_column($body['history'], 'status'))->toBe(['draft', 'submitted', 'denied'])
        ->and($body['history'][2]['remarks'])->toContain('transmittal');
});

it('will not record a denial with no reason', function () {
    $patient = Patient::factory()->create();
    $session = billableSession($patient);
    $billing = biller();

    $claimNo = $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/claims", ['session_public_ids' => [$session->public_id]])
        ->assertCreated()->json('claim_no');

    // A denial nobody can act on is a claim that quietly dies.
    $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/status", ['status' => 'denied'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('remarks');
});

it('will not let a nurse generate a claim', function () {
    $patient = Patient::factory()->create();
    $session = billableSession($patient);

    // Charting and billing are separated on purpose.
    $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/claims", ['session_public_ids' => [$session->public_id]])
        ->assertForbidden();

    expect(DB::table('claims')->count())->toBe(0);
});

it('prices a historical session against the program that was in force then', function () {
    $patient = Patient::factory()->create();
    $ledger = app(BenefitLedger::class);

    // The superseded 2023 package is still in benefit_programs so old claims
    // re-price correctly. Its window is 2023-03-01 to 2024-07-01.
    $then = $ledger->programOn(Carbon\Carbon::parse('2023-06-01'));
    $now = $ledger->programOn(now());

    expect($then)->not->toBeNull()
        ->and($now)->not->toBeNull()
        ->and($then->code)->not->toBe($now->code)
        // Same cap, different money -- which is exactly why the rate is a row.
        ->and((float) $then->case_rate)->not->toBe((float) $now->case_rate);
});

/* -------------------------------------------------------------------------- */
/* Remittance -- what the payer actually decided */
/* -------------------------------------------------------------------------- */

/** A submitted claim for one session, ready for a remittance advice. */
function submittedClaim(Patient $patient): string
{
    $session = billableSession($patient);
    $billing = biller();

    $claimNo = test()->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/claims", ['session_public_ids' => [$session->public_id]])
        ->assertCreated()->json('claim_no');

    test()->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/status", ['status' => 'submitted'])->assertOk();

    return $claimNo;
}

it('records a remittance and lets the money decide the status', function () {
    $patient = Patient::factory()->create();
    $claimNo = submittedClaim($patient);
    $rate = (string) currentProgram()->case_rate;

    $body = $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/remittance", [
            'amount_approved' => $rate,
            'amount_paid' => $rate,
            'external_ref' => 'PH-REM-88213',
        ])
        ->assertOk()
        ->json();

    // Paid in full, and the status was derived rather than asserted.
    expect($body['status'])->toBe('paid')
        ->and((float) $body['amount_approved'])->toBe((float) $rate)
        ->and((float) $body['amount_paid'])->toBe((float) $rate)
        ->and($body['paid_at'])->not->toBeNull();
});

it('marks a short payment as partially paid, not paid', function () {
    $patient = Patient::factory()->create();
    $claimNo = submittedClaim($patient);
    $rate = (string) currentProgram()->case_rate;
    $half = bcdiv($rate, '2', 2);

    $body = $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/remittance", [
            'amount_approved' => $rate,
            'amount_paid' => $half,
        ])
        ->assertOk()
        ->json();

    // A claim reaching `paid` with money still outstanding is the hole this
    // endpoint closes.
    expect($body['status'])->toBe('partially_paid')
        ->and($body['paid_at'])->toBeNull();
});

it('treats an approval of zero as a denial and demands a code', function () {
    $patient = Patient::factory()->create();
    $claimNo = submittedClaim($patient);

    $refused = $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/remittance", [
            'amount_approved' => '0.00',
            'amount_paid' => '0.00',
        ]);

    $refused->assertStatus(422);
    expect($refused->json('message'))->toContain('needs a code to resubmit against');

    $body = $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/remittance", [
            'amount_approved' => '0.00',
            'amount_paid' => '0.00',
            'denial_code' => 'PH-4021',
            'denial_reason' => 'missing attending physician signature',
        ])
        ->assertOk()
        ->json();

    expect($body['status'])->toBe('denied')
        ->and($body['denial_code'])->toBe('PH-4021');
});

it('refuses an approval larger than the claim', function () {
    $patient = Patient::factory()->create();
    $claimNo = submittedClaim($patient);
    $tooMuch = bcadd((string) currentProgram()->case_rate, '1000.00', 2);

    $response = $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/remittance", [
            'amount_approved' => $tooMuch,
            'amount_paid' => $tooMuch,
        ]);

    // A data-entry slip on a remittance advice, not a windfall.
    $response->assertStatus(422);
    expect($response->json('message'))->toContain('Check the remittance advice');
});

it('refuses a payment larger than the approval', function () {
    $patient = Patient::factory()->create();
    $claimNo = submittedClaim($patient);
    $rate = (string) currentProgram()->case_rate;

    $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/remittance", [
            'amount_approved' => bcdiv($rate, '2', 2),
            'amount_paid' => $rate,
        ])
        ->assertStatus(422);
});

it('keeps the remittance in the claim history', function () {
    $patient = Patient::factory()->create();
    $claimNo = submittedClaim($patient);
    $rate = (string) currentProgram()->case_rate;

    $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/remittance", [
            'amount_approved' => $rate, 'amount_paid' => $rate,
        ])->assertOk();

    $body = $this->actingAs(biller(), 'sanctum')->getJson("/api/v1/claims/{$claimNo}")->assertOk()->json();

    expect(array_column($body['history'], 'status'))->toBe(['draft', 'submitted', 'paid'])
        ->and(end($body['history'])['remarks'])->toContain('Remittance: approved');
});

it('reports what the unit is still owed', function () {
    $patient = Patient::factory()->create();
    $claimNo = submittedClaim($patient);
    $rate = (string) currentProgram()->case_rate;
    $half = bcdiv($rate, '2', 2);

    $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/remittance", [
            'amount_approved' => $rate, 'amount_paid' => $half,
        ])->assertOk();

    $body = $this->actingAs(biller(), 'sanctum')
        ->getJson('/api/v1/claims-outstanding')->assertOk()->json();

    // Outstanding is measured against what was approved: the gap between claimed
    // and approved is a denial to appeal, not a debt to chase.
    expect($body['claimed'])->toBe($rate)
        ->and($body['approved'])->toBe($rate)
        ->and($body['paid'])->toBe($half)
        ->and($body['outstanding'])->toBe(bcsub($rate, $half, 2));
});

/* -------------------------------------------------------------------------- */
/* The claim lifecycle -- only along the transitions the ledger allows */
/* -------------------------------------------------------------------------- */

/**
 * A draft claim for one session, returned with the session it covers.
 *
 * @return array{0: string, 1: TreatmentSession}
 */
function draftClaim(Patient $patient): array
{
    $session = billableSession($patient);

    $claimNo = test()->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/claims", ['session_public_ids' => [$session->public_id]])
        ->assertCreated()->json('claim_no');

    return [$claimNo, $session];
}

it('will not mark a claim paid, approved or part-paid by hand', function () {
    $patient = Patient::factory()->create();
    $claimNo = submittedClaim($patient);

    // These are what the payer decided. A status picked from a list cannot
    // say how much was paid, and a claim marked paid with nothing received
    // is exactly the disagreement recordRemittance() exists to prevent.
    foreach (['paid', 'approved', 'partially_paid'] as $status) {
        $response = $this->actingAs(biller(), 'sanctum')
            ->postJson("/api/v1/claims/{$claimNo}/status", ['status' => $status]);

        $response->assertStatus(422);
        expect($response->json('message'))->toContain('Record the remittance instead');
    }

    expect(DB::table('claims')->where('claim_no', $claimNo)->value('status'))->toBe('submitted')
        ->and(DB::table('claims')->where('claim_no', $claimNo)->value('paid_at'))->toBeNull();
});

it('will not take a claim back, or void it, once the payer has it', function () {
    $patient = Patient::factory()->create();
    $claimNo = submittedClaim($patient);

    foreach (['draft', 'ready'] as $status) {
        $this->actingAs(biller(), 'sanctum')
            ->postJson("/api/v1/claims/{$claimNo}/status", ['status' => $status])
            ->assertStatus(422);
    }

    // Voiding frees the sessions for a new claim -- which, for a claim the
    // payer is holding, is how one treatment gets paid twice.
    $void = $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/status", [
            'status' => 'void',
            'remarks' => 'generated against the wrong sessions',
        ]);

    $void->assertStatus(422);

    expect($void->json('message'))->toContain('bill them twice')
        ->and(DB::table('claim_sessions')->count())->toBe(1);
});

it('never reopens a void claim', function () {
    $patient = Patient::factory()->create();
    [$claimNo] = draftClaim($patient);

    $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/status", [
            'status' => 'void',
            'remarks' => 'raised for the wrong month by mistake',
        ])->assertOk();

    $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/status", ['status' => 'submitted'])
        ->assertStatus(422)
        ->assertJsonPath('message', "Claim {$claimNo} is void. A void claim is never reopened; generate a new one.");
});

it('voids a draft claim and frees its sessions to be claimed again', function () {
    $patient = Patient::factory()->create();
    [$claimNo, $session] = draftClaim($patient);

    $body = $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/status", [
            'status' => 'void',
            'remarks' => 'raised for the wrong month by mistake',
        ])
        ->assertOk()
        ->json();

    expect($body['status'])->toBe('void')
        ->and($body['sessions'])->toBe([])
        ->and(end($body['history'])['remarks'])->toContain('1 session(s) released')
        ->and(DB::table('treatment_sessions')->where('id', $session->id)->value('benefit_claim_id'))->toBeNull();

    // What the void claim covered is still answerable from the audit trail.
    $audit = DB::table('audit_logs')->where('auditable_type', 'table:claim_sessions')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->action)->toBe('DELETE')
        ->and(json_decode((string) $audit->before_data, true)['session_public_id'])->toBe($session->public_id)
        ->and($audit->reason)->toContain('wrong month');

    // Before this, the session was stranded: counted as unclaimed by the
    // utilisation view, refused as "already on claim" by the ledger.
    $again = $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/claims", ['session_public_ids' => [$session->public_id]])
        ->assertCreated()
        ->json();

    expect($again['claim_no'])->not->toBe($claimNo)
        // The void claim gave its allotment back; this is session 1 again.
        ->and($again['sessions'][0]['benefit_seq_no'])->toBe(1)
        ->and($again['utilisation']['sessions_claimed'])->toBe(1);
});

it('will not void a claim without a reason someone can follow', function () {
    $patient = Patient::factory()->create();
    [$claimNo] = draftClaim($patient);

    foreach ([null, 'mistake'] as $remarks) {
        $this->actingAs(biller(), 'sanctum')
            ->postJson("/api/v1/claims/{$claimNo}/status", ['status' => 'void', 'remarks' => $remarks])
            ->assertStatus(422)
            ->assertJsonValidationErrors('remarks');
    }

    expect(DB::table('claim_sessions')->count())->toBe(1);
});

it('will not record a return from the payer without the reason', function () {
    $patient = Patient::factory()->create();
    $claimNo = submittedClaim($patient);

    $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/status", ['status' => 'returned'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('remarks');

    $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/status", [
            'status' => 'returned',
            'remarks' => 'RTH: transmittal lacks the member signature',
        ])->assertOk()->assertJsonPath('status', 'returned');

    // A returned claim is refiled as it stands.
    $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/status", ['status' => 'resubmitted'])
        ->assertOk()
        ->assertJsonPath('next_statuses', ['acknowledged', 'in_process', 'returned', 'denied'])
        ->assertJsonPath('accepts_remittance', true);
});

it('tells the screen where a claim can go next', function () {
    $patient = Patient::factory()->create();
    [$claimNo] = draftClaim($patient);

    $this->actingAs(biller(), 'sanctum')->getJson("/api/v1/claims/{$claimNo}")
        ->assertOk()
        ->assertJsonPath('next_statuses', ['ready', 'submitted', 'void'])
        ->assertJsonPath('accepts_remittance', false);

    $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/status", ['status' => 'submitted'])
        ->assertOk()
        ->assertJsonPath('next_statuses', ['acknowledged', 'in_process', 'returned', 'denied'])
        ->assertJsonPath('accepts_remittance', true);
});

it('refuses a remittance against a claim that was never submitted', function () {
    $patient = Patient::factory()->create();
    [$claimNo] = draftClaim($patient);
    $rate = (string) currentProgram()->case_rate;

    // This used to mark a claim paid that had never been sent.
    $response = $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/remittance", ['amount_approved' => $rate, 'amount_paid' => $rate]);

    $response->assertStatus(422);

    expect($response->json('message'))->toContain('has not been submitted')
        ->and(DB::table('claims')->where('claim_no', $claimNo)->value('status'))->toBe('draft');
});

it('refuses a remittance against a void claim', function () {
    $patient = Patient::factory()->create();
    [$claimNo] = draftClaim($patient);
    $rate = (string) currentProgram()->case_rate;

    $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/status", [
            'status' => 'void',
            'remarks' => 'raised for the wrong month by mistake',
        ])->assertOk();

    // This used to bring the claim back to life as paid, with the money counted
    // in what the unit is owed.
    $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/remittance", ['amount_approved' => $rate, 'amount_paid' => $rate])
        ->assertStatus(422);

    expect(DB::table('claims')->where('claim_no', $claimNo)->value('status'))->toBe('void')
        ->and(DB::table('claims')->where('claim_no', $claimNo)->value('amount_paid'))->toBeNull();
});

it('settles a part-paid claim with a follow-up advice, but never lets paid to date go down', function () {
    $patient = Patient::factory()->create();
    $claimNo = submittedClaim($patient);
    $rate = (string) currentProgram()->case_rate;
    $first = bcdiv($rate, '2', 2);

    $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/remittance", ['amount_approved' => $rate, 'amount_paid' => $first])
        ->assertOk()
        ->assertJsonPath('status', 'partially_paid')
        ->assertJsonPath('amount_outstanding', bcsub($rate, $first, 2));

    // Keying the second voucher on its own instead of the running total would
    // overwrite the record of money the unit already holds.
    $smaller = bcsub($first, '100.00', 2);

    $refused = $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/remittance", ['amount_approved' => $rate, 'amount_paid' => $smaller]);

    $refused->assertStatus(422);
    expect($refused->json('message'))->toContain('paid to date cannot go down');

    $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/remittance", ['amount_approved' => $rate, 'amount_paid' => $rate])
        ->assertOk()
        ->assertJsonPath('status', 'paid')
        ->assertJsonPath('amount_outstanding', '0.00');

    // Paid in full is the end of the line.
    $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/remittance", ['amount_approved' => $rate, 'amount_paid' => $rate])
        ->assertStatus(422);
});

it('shows the denial code, who moved the claim, and when, with a zone on it', function () {
    $patient = Patient::factory()->create();
    $claimNo = submittedClaim($patient);

    $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/remittance", [
            'amount_approved' => '0.00',
            'amount_paid' => '0.00',
            'denial_code' => 'PH-4021',
            'denial_reason' => 'missing attending physician signature',
        ])->assertOk();

    $body = $this->actingAs(biller(), 'sanctum')->getJson("/api/v1/claims/{$claimNo}")->assertOk()->json();

    expect($body['denial_code'])->toBe('PH-4021')
        ->and($body['patient']['public_id'])->toBe($patient->public_id)
        ->and($body['next_statuses'])->toBe([])
        ->and($body['history'][0]['changed_by'])->not->toBeNull()
        // A raw DATETIME read as local time in a browser is a clock eight
        // hours out in Manila (CLAUDE.md, MySQL rule 11).
        ->and($body['history'][0]['changed_at'])->toMatch('/T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
});

it('lists claims with the patient to link to, filtered by status or search', function () {
    $patient = Patient::factory()->create();
    [$claimNo] = draftClaim($patient);
    submittedClaim(Patient::factory()->create());

    $page = $this->actingAs(biller(), 'sanctum')->getJson('/api/v1/claims?status=draft')->assertOk()->json();

    // The envelope every other list uses; this one used to be the flat paginator.
    expect($page)->toHaveKeys(['data', 'links', 'meta'])
        ->and($page['data'])->toHaveCount(1)
        ->and($page['data'][0]['claim_no'])->toBe($claimNo)
        ->and($page['data'][0]['patient']['public_id'])->toBe($patient->public_id)
        ->and($page['data'][0])->not->toHaveKey('id');

    $found = $this->actingAs(biller(), 'sanctum')
        ->getJson('/api/v1/claims?q='.urlencode($patient->mrn))->assertOk()->json('data');

    expect(array_column($found, 'claim_no'))->toBe([$claimNo]);
});

it('counts only claims that have gone to a payer in what the unit is owed', function () {
    $patient = Patient::factory()->create();
    [$claimNo] = draftClaim($patient);

    $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/status", ['status' => 'ready'])
        ->assertOk();

    // Ready is still in the building. It used to count as claimed.
    $this->actingAs(biller(), 'sanctum')->getJson('/api/v1/claims-outstanding')
        ->assertOk()
        ->assertJsonPath('claimed', '0.00');

    $this->actingAs(biller(), 'sanctum')
        ->postJson("/api/v1/claims/{$claimNo}/status", ['status' => 'submitted'])
        ->assertOk();

    $this->actingAs(biller(), 'sanctum')->getJson('/api/v1/claims-outstanding')
        ->assertOk()
        ->assertJsonPath('claimed', (string) currentProgram()->case_rate);
});
