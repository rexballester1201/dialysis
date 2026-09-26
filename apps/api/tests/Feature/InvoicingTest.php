<?php

declare(strict_types=1);

use App\Domain\Billing\Models\Invoice;
use App\Domain\Clinical\Models\TreatmentSession;
use App\Domain\Core\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Patient invoicing
|--------------------------------------------------------------------------
| A claim is what the payer is asked for; an invoice is what is left. For a
| package with no-balance billing they cancel and the patient owes nothing --
| which is the entire point of that flag, and getting it wrong bills a patient
| for something the law already covers.
|
| Prices come from the service_items list, never from a literal, for the same
| reason the case rate does not: they move.
*/

uses(TestCase::class, RefreshDatabase::class);

function invoicedSession(Patient $patient, ?string $date = null): TreatmentSession
{
    return TreatmentSession::factory()->locked()->create([
        'patient_id' => $patient->id,
        'session_date' => $date ?? now()->toDateString(),
        'is_billable' => 1,
    ]);
}

function listPrice(string $code = 'HD-SESSION'): string
{
    return (string) DB::table('service_items')->where('code', $code)->value('unit_price');
}

it('prices each line from the service list, by modality', function () {
    $patient = Patient::factory()->create();
    $one = invoicedSession($patient, now()->subDays(2)->toDateString());
    $two = invoicedSession($patient, now()->toDateString());

    $body = $this->actingAs(staffWithRole('billing'), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/invoices", [
            'session_public_ids' => [$one->public_id, $two->public_id],
        ])
        ->assertCreated()
        ->json();

    $expected = bcmul(listPrice(), '2', 2);

    expect($body['subtotal'])->toBe($expected)
        // Nothing was claimed, so the patient carries the whole thing.
        ->and($body['payer_covered'])->toBe('0.00')
        ->and($body['patient_due'])->toBe($expected)
        ->and($body['balance'])->toBe($expected)
        ->and($body['status'])->toBe('draft');
});

it('charges the patient nothing when the package forbids balance billing', function () {
    $patient = Patient::factory()->create();
    $session = invoicedSession($patient);
    $billing = staffWithRole('billing');

    // The seeded PhilHealth package has no_balance_billing = 1.
    $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/claims", ['session_public_ids' => [$session->public_id]])
        ->assertCreated();

    $body = $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/invoices", ['session_public_ids' => [$session->public_id]])
        ->assertCreated()
        ->json();

    // Whatever the list price and the case rate happen to be, the patient owes
    // nothing. Billing them the difference is what this flag forbids.
    expect($body['patient_due'])->toBe('0.00')
        ->and($body['balance'])->toBe('0.00')
        ->and($body['payer_covered'])->toBe($body['subtotal']);
});

it('never leaves a negative amount due when the payer covered more than the list price', function () {
    $patient = Patient::factory()->create();
    $session = invoicedSession($patient);

    // A claim paying above the list price, with balance billing allowed.
    $payer = DB::table('payers')->where('code', 'PCSO')->value('id');
    $program = DB::table('benefit_programs')->insertGetId([
        'payer_id' => $payer,
        'code' => 'TEST_GENEROUS',
        'name' => 'Test package paying above list',
        'modality' => 'hd',
        'sessions_per_period' => 100,
        'period_kind' => 'calendar_year',
        'case_rate' => bcadd(listPrice(), '1000.00', 2),
        'currency' => 'PHP',
        'no_balance_billing' => 0,
        'effective_from' => now()->subYear()->toDateString(),
    ]);

    $claim = DB::table('claims')->insertGetId([
        'claim_no' => 'CLM-OVER-0001',
        'patient_id' => $patient->id,
        'payer_id' => $payer,
        'program_id' => $program,
        'service_from' => $session->session_date->toDateString(),
        'service_to' => $session->session_date->toDateString(),
        'session_count' => 1,
        'amount_claimed' => bcadd(listPrice(), '1000.00', 2),
        'status' => 'paid',
    ]);

    DB::table('claim_sessions')->insert([
        'session_id' => $session->id,
        'claim_id' => $claim,
        'amount' => bcadd(listPrice(), '1000.00', 2),
    ]);

    $body = $this->actingAs(staffWithRole('billing'), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/invoices", ['session_public_ids' => [$session->public_id]])
        ->assertCreated()
        ->json();

    // The surplus is a payer reconciliation matter, not a credit handed back on
    // the patient's bill.
    expect($body['patient_due'])->toBe('0.00');
});

it('will not invoice an unsigned session', function () {
    $patient = Patient::factory()->create();

    $unsigned = TreatmentSession::factory()->completed()->create([
        'patient_id' => $patient->id,
        'is_billable' => 1,
    ]);

    $response = $this->actingAs(staffWithRole('billing'), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/invoices", ['session_public_ids' => [$unsigned->public_id]]);

    $response->assertStatus(422);

    expect($response->json('message'))->toContain('not signed')
        ->and(DB::table('invoices')->count())->toBe(0);
});

it('will not put the same session on two invoices', function () {
    $patient = Patient::factory()->create();
    $session = invoicedSession($patient);
    $billing = staffWithRole('billing');

    $first = $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/invoices", ['session_public_ids' => [$session->public_id]])
        ->assertCreated();

    $second = $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/invoices", ['session_public_ids' => [$session->public_id]]);

    $second->assertStatus(422);

    expect($second->json('message'))->toContain($first->json('invoice_no'))
        ->and(DB::table('invoices')->count())->toBe(1)
        ->and(DB::table('invoice_lines')->count())->toBe(1);
});

it('tracks a part payment and then settles the invoice', function () {
    $patient = Patient::factory()->create();
    $session = invoicedSession($patient);
    $billing = staffWithRole('billing');

    $invoiceNo = $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/invoices", ['session_public_ids' => [$session->public_id]])
        ->assertCreated()
        ->json('invoice_no');

    $due = listPrice();
    $part = bcdiv($due, '2', 2);

    $afterPart = $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/invoices/{$invoiceNo}/payments", ['amount' => $part, 'method' => 'cash'])
        ->assertCreated()
        ->json();

    expect($afterPart['status'])->toBe('partially_paid')
        // balance is generated: patient_due - amount_paid.
        ->and($afterPart['balance'])->toBe(bcsub($due, $part, 2));

    $settled = $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/invoices/{$invoiceNo}/payments", [
            'amount' => bcsub($due, $part, 2),
            'method' => 'bank_transfer',
            'reference_no' => 'BT-99123',
        ])
        ->assertCreated()
        ->json();

    expect($settled['status'])->toBe('paid')
        ->and($settled['balance'])->toBe('0.00')
        ->and($settled['amount_paid'])->toBe($due);
});

it('refuses a zero payment, which the database also forbids', function () {
    $patient = Patient::factory()->create();
    $session = invoicedSession($patient);
    $billing = staffWithRole('billing');

    $invoiceNo = $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/invoices", ['session_public_ids' => [$session->public_id]])
        ->assertCreated()->json('invoice_no');

    // pay_amount_ck forbids zero; the request refuses it first.
    $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/invoices/{$invoiceNo}/payments", ['amount' => 0, 'method' => 'cash'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('amount');
});

it('shows the lines and the payments behind a balance', function () {
    $patient = Patient::factory()->create();
    $billing = staffWithRole('billing');

    $one = invoicedSession($patient, now()->subDays(3)->toDateString());
    $two = invoicedSession($patient, now()->toDateString());

    $invoiceNo = $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/invoices", ['session_public_ids' => [$one->public_id, $two->public_id]])
        ->assertCreated()->json('invoice_no');

    $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/invoices/{$invoiceNo}/payments", ['amount' => '100.00', 'method' => 'cash'])
        ->assertCreated();

    $body = $this->actingAs($billing, 'sanctum')->getJson("/api/v1/invoices/{$invoiceNo}")->assertOk()->json();

    expect($body['lines'])->toHaveCount(2)
        ->and($body['payments'])->toHaveCount(1)
        // line_total is generated: qty * unit_price - discount.
        ->and($body['lines'][0]['line_total'])->toBe(listPrice())
        ->and($body['payments'][0]['method'])->toBe('cash');
});

it('will not let a nurse raise an invoice', function () {
    $patient = Patient::factory()->create();
    $session = invoicedSession($patient);

    $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/invoices", ['session_public_ids' => [$session->public_id]])
        ->assertForbidden();

    expect(DB::table('invoices')->count())->toBe(0);
});

/* -------------------------------------------------------------------------- */
/* Choosing sessions, issuing, voiding, and money that follows the figures */
/* -------------------------------------------------------------------------- */

/** A draft invoice for one fresh session, by number. */
function draftInvoice(Patient $patient): string
{
    $session = invoicedSession($patient);

    return test()->actingAs(staffWithRole('billing'), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/invoices", ['session_public_ids' => [$session->public_id]])
        ->assertCreated()
        ->json('invoice_no');
}

it('lists what could be invoiced, with the claim each session is on, and not what already is', function () {
    $patient = Patient::factory()->create();
    $billing = staffWithRole('billing');

    $claimed = invoicedSession($patient, now()->subDays(4)->toDateString());
    $invoiced = invoicedSession($patient, now()->subDays(2)->toDateString());
    $plain = invoicedSession($patient, now()->toDateString());

    $claimNo = $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/claims", ['session_public_ids' => [$claimed->public_id]])
        ->assertCreated()->json('claim_no');

    $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/invoices", ['session_public_ids' => [$invoiced->public_id]])
        ->assertCreated();

    $sessions = $this->actingAs($billing, 'sanctum')
        ->getJson("/api/v1/patients/{$patient->public_id}/invoiceable")
        ->assertOk()
        ->json('sessions');

    expect(array_column($sessions, 'public_id'))->toBe([$claimed->public_id, $plain->public_id])
        ->and($sessions[0]['claim_no'])->toBe($claimNo)
        ->and($sessions[0]['claim_status'])->toBe('draft')
        ->and($sessions[1]['claim_no'])->toBeNull()
        // The price it will be charged at, off the list -- never a literal.
        ->and($sessions[1]['list_price'])->toBe(listPrice())
        ->and($sessions[1])->not->toHaveKey('id');
});

it('issues a draft, dated the day it is issued rather than drafted', function () {
    $patient = Patient::factory()->create();
    $invoiceNo = draftInvoice($patient);

    DB::table('invoices')->where('invoice_no', $invoiceNo)->update(['issued_on' => now()->subDays(5)->toDateString()]);

    $this->actingAs(staffWithRole('billing'), 'sanctum')
        ->postJson("/api/v1/invoices/{$invoiceNo}/issue")
        ->assertOk()
        ->assertJsonPath('status', 'issued')
        ->assertJsonPath('issued_on', now()->toDateString())
        ->assertJsonPath('can_issue', false);

    // Issuing is a one-way step.
    $this->actingAs(staffWithRole('billing'), 'sanctum')
        ->postJson("/api/v1/invoices/{$invoiceNo}/issue")
        ->assertStatus(422);
});

it('voids an invoice raised in error and frees its sessions to be invoiced again', function () {
    $patient = Patient::factory()->create();
    $billing = staffWithRole('billing');
    $session = invoicedSession($patient);

    $invoiceNo = $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/invoices", ['session_public_ids' => [$session->public_id]])
        ->assertCreated()->json('invoice_no');

    $body = $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/invoices/{$invoiceNo}/void", ['reason' => 'drafted against the wrong session'])
        ->assertOk()
        ->json();

    expect($body['status'])->toBe('void')
        ->and($body['notes'])->toContain('drafted against the wrong session')
        ->and($body['takes_payments'])->toBeFalse()
        // The lines stay: they are what the voided copy said.
        ->and($body['lines'])->toHaveCount(1);

    // The reason reached the audit row, not just the notes.
    $audit = DB::table('audit_logs')
        ->where('auditable_type', Invoice::class)
        ->where('action', 'UPDATE')
        ->latest('id')
        ->first();

    expect($audit?->reason)->toBe('drafted against the wrong session');

    // Before this, nothing could make an invoice void, so the session was held
    // by the mistaken draft for good.
    $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/invoices", ['session_public_ids' => [$session->public_id]])
        ->assertCreated();
});

it('will not void an invoice without a reason', function () {
    $patient = Patient::factory()->create();
    $invoiceNo = draftInvoice($patient);

    $this->actingAs(staffWithRole('billing'), 'sanctum')
        ->postJson("/api/v1/invoices/{$invoiceNo}/void", ['reason' => 'oops'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    expect(DB::table('invoices')->where('invoice_no', $invoiceNo)->value('status'))->toBe('draft');
});

it('will not void an invoice holding money until it has been refunded', function () {
    $patient = Patient::factory()->create();
    $billing = staffWithRole('billing');
    $invoiceNo = draftInvoice($patient);

    $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/invoices/{$invoiceNo}/payments", ['amount' => '500.00', 'method' => 'cash'])
        ->assertCreated()
        ->assertJsonPath('can_void', false);

    $refused = $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/invoices/{$invoiceNo}/void", ['reason' => 'drafted against the wrong session']);

    $refused->assertStatus(422);
    expect($refused->json('message'))->toContain('500.00 received');

    $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/invoices/{$invoiceNo}/payments", ['amount' => '-500.00', 'method' => 'cash', 'notes' => 'refunded at the desk'])
        ->assertCreated()
        ->assertJsonPath('can_void', true);

    $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/invoices/{$invoiceNo}/void", ['reason' => 'drafted against the wrong session'])
        ->assertOk()
        ->assertJsonPath('status', 'void');
});

it('refuses a payment larger than what is still owed', function () {
    $patient = Patient::factory()->create();
    $invoiceNo = draftInvoice($patient);
    $tooMuch = bcadd(listPrice(), '0.01', 2);

    // 63,500 keyed for 6,350 used to mark the invoice paid over a large
    // negative balance.
    $response = $this->actingAs(staffWithRole('billing'), 'sanctum')
        ->postJson("/api/v1/invoices/{$invoiceNo}/payments", ['amount' => $tooMuch, 'method' => 'cash']);

    $response->assertStatus(422);

    expect($response->json('message'))->toContain('more than the '.listPrice().' still owed')
        ->and(DB::table('payments')->count())->toBe(0);
});

it('refuses a refund larger than what was received', function () {
    $patient = Patient::factory()->create();
    $billing = staffWithRole('billing');
    $invoiceNo = draftInvoice($patient);

    $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/invoices/{$invoiceNo}/payments", ['amount' => '200.00', 'method' => 'cash'])
        ->assertCreated();

    $response = $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/invoices/{$invoiceNo}/payments", ['amount' => '-200.01', 'method' => 'cash']);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('more than the 200.00 received');
});

it('puts a fully refunded invoice back to issued, not paid', function () {
    $patient = Patient::factory()->create();
    $billing = staffWithRole('billing');
    $invoiceNo = draftInvoice($patient);

    $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/invoices/{$invoiceNo}/payments", ['amount' => listPrice(), 'method' => 'cash'])
        ->assertCreated()
        ->assertJsonPath('status', 'paid');

    // This used to stay `paid` with the whole amount owed again.
    $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/invoices/{$invoiceNo}/payments", ['amount' => '-'.listPrice(), 'method' => 'cash'])
        ->assertCreated()
        ->assertJsonPath('status', 'issued')
        ->assertJsonPath('balance', listPrice());
});

it('turns away a payment on a void invoice as a closed record, not a permissions problem', function () {
    $patient = Patient::factory()->create();
    $billing = staffWithRole('billing');
    $invoiceNo = draftInvoice($patient);

    $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/invoices/{$invoiceNo}/void", ['reason' => 'drafted against the wrong session'])
        ->assertOk();

    // A 403 would tell a billing officer their role cannot take payments.
    $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/invoices/{$invoiceNo}/payments", ['amount' => '100.00', 'method' => 'cash'])
        ->assertStatus(409)
        ->assertJsonPath('message', "Invoice {$invoiceNo} is void and takes no payments.");
});

it('shows each line with its session and claim, and the payments with who took them', function () {
    $patient = Patient::factory()->create();
    $billing = staffWithRole('billing');
    $session = invoicedSession($patient);

    $claimNo = $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/claims", ['session_public_ids' => [$session->public_id]])
        ->assertCreated()->json('claim_no');

    $invoiceNo = $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/invoices", ['session_public_ids' => [$session->public_id]])
        ->assertCreated()->json('invoice_no');

    $body = $this->actingAs($billing, 'sanctum')->getJson("/api/v1/invoices/{$invoiceNo}")->assertOk()->json();

    expect($body['patient']['public_id'])->toBe($patient->public_id)
        ->and($body['lines'][0]['session_public_id'])->toBe($session->public_id)
        ->and($body['lines'][0]['claim_no'])->toBe($claimNo)
        ->and($body['lines'][0])->not->toHaveKey('session_id');

    $page = $this->actingAs($billing, 'sanctum')->getJson('/api/v1/invoices')->assertOk()->json();

    expect($page['data'][0]['patient']['public_id'])->toBe($patient->public_id)
        ->and($page['data'][0])->not->toHaveKey('id');
});

it('carries the void reason into the audit row for a form-encoded call too', function () {
    $patient = Patient::factory()->create();
    $billing = staffWithRole('billing');
    $invoiceNo = draftInvoice($patient);

    // A FormRequest shares only the JSON body with the request AuditObserver
    // reads, so a reason merged into the FormRequest reached the audit row for
    // JSON and was lost for a form post. This pins the form post.
    $this->actingAs($billing, 'sanctum')
        ->post("/api/v1/invoices/{$invoiceNo}/void", ['reason' => 'drafted against the wrong session'], ['Accept' => 'application/json'])
        ->assertOk();

    $reason = DB::table('audit_logs')
        ->where('auditable_type', Invoice::class)
        ->where('action', 'UPDATE')
        ->latest('id')
        ->value('reason');

    expect($reason)->toBe('drafted against the wrong session');
});

it('applies no-balance billing to the sessions claimed under it, not to the whole invoice', function () {
    $patient = Patient::factory()->create();
    $billing = staffWithRole('billing');

    $claimed = invoicedSession($patient, now()->subDays(2)->toDateString());
    $unclaimed = invoicedSession($patient, now()->toDateString());

    // The seeded PhilHealth package has no_balance_billing = 1.
    $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/claims", ['session_public_ids' => [$claimed->public_id]])
        ->assertCreated();

    $body = $this->actingAs($billing, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/invoices", [
            'session_public_ids' => [$claimed->public_id, $unclaimed->public_id],
        ])
        ->assertCreated()
        ->json();

    // The claimed line is covered by the package; the unclaimed one is not on
    // any claim, so nobody else is paying for it. It used to be zeroed too.
    expect($body['subtotal'])->toBe(bcmul(listPrice(), '2', 2))
        ->and($body['payer_covered'])->toBe(listPrice())
        ->and($body['patient_due'])->toBe(listPrice());
});
