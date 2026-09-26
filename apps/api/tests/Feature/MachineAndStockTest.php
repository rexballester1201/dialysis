<?php

declare(strict_types=1);

use App\Domain\Clinical\Models\TreatmentSession;
use App\Domain\Core\Models\Patient;
use App\Domain\Core\Models\Staff;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Machines and stock
|--------------------------------------------------------------------------
| Two things this file exists for.
|
| `stock_transactions_ai` is the one trigger of the six with no Pest test. It is
| what keeps a lot's running balance and its movement history from disagreeing,
| and `sl_qty_ck` is what stops either being talked below zero.
|
| And invariant 1 covers machines as well as chairs. CohortGuard has always known
| that; nothing called it at the moment a machine was assigned.
*/

uses(TestCase::class, RefreshDatabase::class);

function biomed(): Staff
{
    return staffWithRole('technician');
}

function reusableItem(): int
{
    return (int) DB::table('items')->where('sku', 'BLS-AV')->value('id');
}

/** A session checked in and ready for the needle, with a passing water check. */
function assignableSession(array $overrides = []): TreatmentSession
{
    passingWaterCheck();

    return TreatmentSession::factory()->create(array_merge([
        'status' => 'checked_in',
        'session_date' => now()->toDateString(),
        'checked_in_at' => now()->subMinutes(10),
        'pre_weight_kg' => '60.40',
        'dry_weight_kg' => '57.50',
    ], $overrides));
}

/* -------------------------------------------------------------------------- */
/* Machines */
/* -------------------------------------------------------------------------- */

it('will not start a treatment on a machine that is under repair', function () {
    $machine = machineFor(['status' => 'under_repair']);

    $session = assignableSession();

    $response = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/start", ['machine_id' => $machine->id]);

    // Unlike the cohort rule, this one blocks: a machine under repair is not a
    // reporting matter.
    $response->assertStatus(422);

    expect($response->json('message'))->toContain('under_repair')
        ->and($session->fresh()->started_at)->toBeNull();
});

it('refuses a machine dedicated to another cohort', function () {
    $patient = Patient::factory()->hbvReactive()->create();

    // A machine dedicated to the clean cohort, and an HBV patient.
    $machine = machineFor(['dedicated_cohort' => 'clean', 'status' => 'in_service']);

    // Seated in an HBV isolation chair, so the chair is right and only the
    // machine is wrong -- otherwise both halves of invariant 1 fire and this
    // test would pass for the wrong reason.
    $session = assignableSession([
        'patient_id' => $patient->id,
        'station_id' => DB::table('stations')->where('code', 'ISO-B1')->value('id'),
    ]);

    $response = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/start", ['machine_id' => $machine->id]);

    // 409, and nothing started. A warning in a response body is only as good as
    // the screen that renders it, and this is the highest-consequence rule here.
    $response->assertStatus(409);

    expect($response->json('violations'))->toHaveCount(1)
        ->and($response->json('violations.0'))->toContain($machine->asset_tag)
        ->and($response->json('override_with'))->toBe('cohort_override_reason')
        ->and($session->fresh()->started_at)->toBeNull();
});

it('proceeds past the cohort rule when someone gives a reason, and records it', function () {
    $patient = Patient::factory()->hbvReactive()->create();
    $machine = machineFor(['dedicated_cohort' => 'clean', 'status' => 'in_service']);

    $session = assignableSession([
        'patient_id' => $patient->id,
        'station_id' => DB::table('stations')->where('code', 'ISO-B1')->value('id'),
    ]);

    $body = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/start", [
            'machine_id' => $machine->id,
            'cohort_override_reason' => 'both HBV machines down for repair, nephrologist authorised',
        ])
        ->assertOk()
        ->json();

    expect($body['status'])->toBe('in_progress')
        ->and($body['cohort_overrides'])->toHaveCount(1);

    // The reason is on the chart where a clinician reviewing the record sees it,
    // not only in a log nobody opens.
    $note = DB::table('session_notes')->where('session_id', $session->id)->latest('id')->first();

    expect($note->body)->toContain('INFECTION CONTROL OVERRIDE')
        ->and($note->body)->toContain('both HBV machines down')
        // And the detective view still sees the booking, as the backstop.
        ->and(DB::table('v_cohort_violation')->count())->toBe(1);
});

it('will not accept a hand-wave as an override reason', function () {
    $patient = Patient::factory()->hbvReactive()->create();
    $machine = machineFor(['dedicated_cohort' => 'clean', 'status' => 'in_service']);

    $session = assignableSession([
        'patient_id' => $patient->id,
        'station_id' => DB::table('stations')->where('code', 'ISO-B1')->value('id'),
    ]);

    $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/start", [
            'machine_id' => $machine->id,
            'cohort_override_reason' => 'ok',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('cohort_override_reason');

    expect($session->fresh()->started_at)->toBeNull();
});

it('refuses a machine that last treated a different cohort with no clean since', function () {
    $hbv = Patient::factory()->hbvReactive()->create();
    $machine = machineFor(['dedicated_cohort' => null, 'status' => 'in_service']);

    // The machine's previous patient was HBV, and nothing has been recorded since.
    TreatmentSession::factory()->completed()->create([
        'patient_id' => $hbv->id,
        'machine_id' => $machine->id,
        'session_date' => now()->subDay()->toDateString(),
    ]);

    $session = assignableSession();

    $response = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/start", ['machine_id' => $machine->id]);

    // Categorical, not an interval: how often a machine must be cleaned is unit
    // policy, but that it must be cleaned between cohorts is not.
    $response->assertStatus(409);

    expect($response->json('violations.0'))->toContain('last treated a hbv patient')
        ->and($session->fresh()->started_at)->toBeNull();
});

it('lets the machine through once the disinfection cycle is recorded', function () {
    $hbv = Patient::factory()->hbvReactive()->create();
    $machine = machineFor(['dedicated_cohort' => null, 'status' => 'in_service']);

    TreatmentSession::factory()->completed()->create([
        'patient_id' => $hbv->id,
        'machine_id' => $machine->id,
        'session_date' => now()->subDay()->toDateString(),
    ]);

    $this->actingAs(biomed(), 'sanctum')
        ->postJson("/api/v1/machines/{$machine->id}/disinfection", [
            'method' => 'chemical',
            'agent' => 'peracetic acid',
            'residual_test_done' => true,
            'residual_test_result' => 'negative',
        ])
        ->assertCreated();

    $session = assignableSession();

    $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/start", ['machine_id' => $machine->id])
        ->assertOk()
        ->assertJsonPath('cohort_overrides', []);
});

it('says nothing when the machine and the chair both suit the patient', function () {
    $machine = machineFor(['dedicated_cohort' => null, 'status' => 'in_service']);

    $session = assignableSession();

    $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/sessions/{$session->public_id}/start", ['machine_id' => $machine->id])
        ->assertOk()
        ->assertJsonPath('cohort_overrides', []);
});

it('takes a machine out of service when it fails a safety test', function () {
    $machine = machineFor(['status' => 'in_service']);

    $body = $this->actingAs(biomed(), 'sanctum')
        ->postJson("/api/v1/machines/{$machine->id}/maintenance", [
            'maintenance_type' => 'safety_test',
            'passed' => false,
            'description' => 'earth leakage above limit',
        ])
        ->assertCreated()
        ->json();

    // A machine that failed its electrical safety check and is still on the
    // board is the failure this record exists to prevent.
    expect($body['status'])->toBe('under_repair')
        ->and($body['unavailable_reason'])->toContain('under_repair')
        ->and(DB::table('machines')->where('id', $machine->id)->value('status'))->toBe('under_repair');
});

it('records a disinfection cycle against the machine', function () {
    $machine = machineFor();

    $this->actingAs(biomed(), 'sanctum')
        ->postJson("/api/v1/machines/{$machine->id}/disinfection", [
            'method' => 'heat_citric',
            'agent' => 'citric acid 50%',
            'duration_min' => 45,
            'residual_test_done' => true,
            'residual_test_result' => 'negative',
        ])
        ->assertCreated();

    $body = $this->actingAs(biomed(), 'sanctum')
        ->getJson("/api/v1/machines/{$machine->id}")->assertOk()->json();

    expect($body['disinfection'])->toHaveCount(1)
        ->and($body['disinfection'][0]['method'])->toBe('heat_citric')
        ->and($body['disinfection'][0]['residual_test_result'])->toBe('negative');
});

it('lists maintenance that has fallen due', function () {
    $machine = machineFor();

    DB::table('machine_maintenance_logs')->insert([
        'machine_id' => $machine->id,
        'maintenance_type' => 'preventive',
        'performed_on' => now()->subMonths(7)->toDateString(),
        'passed' => 1,
        'next_due_on' => now()->subWeek()->toDateString(),
    ]);

    $body = $this->actingAs(biomed(), 'sanctum')->getJson('/api/v1/machines')->assertOk()->json();

    expect($body['maintenance_due'])->toHaveCount(1)
        ->and($body['maintenance_due'][0]['asset_tag'])->toBe($machine->asset_tag);
});

/* -------------------------------------------------------------------------- */
/* Stock -- and the stock_transactions_ai trigger */
/* -------------------------------------------------------------------------- */

it('lets the trigger move the balance, never the service', function () {
    $item = reusableItem();

    $body = $this->actingAs(biomed(), 'sanctum')
        ->postJson('/api/v1/stock/receipts', [
            'item_id' => $item,
            'lot_no' => 'LOT-A',
            'qty' => 100,
            'expiry_date' => now()->addYear()->toDateString(),
        ])
        ->assertCreated()
        ->json();

    // The service inserts the lot with qty_on_hand 0 and lets the AFTER INSERT
    // trigger add the movement. If it set the balance itself the receipt would
    // be counted twice.
    expect((float) $body['qty_on_hand'])->toBe(100.0);

    $lot = DB::table('stock_lots')->where('lot_no', 'LOT-A')->first();

    expect((float) $lot->qty_received)->toBe(100.0)
        ->and(DB::table('stock_transactions')->where('lot_id', $lot->id)->count())->toBe(1);
});

it('issues nearest expiry first', function () {
    $item = reusableItem();
    $tech = biomed();
    $session = TreatmentSession::factory()->create();

    foreach ([['LOT-LATER', now()->addYear()], ['LOT-SOONER', now()->addMonth()]] as [$lotNo, $expiry]) {
        $this->actingAs($tech, 'sanctum')->postJson('/api/v1/stock/receipts', [
            'item_id' => $item, 'lot_no' => $lotNo, 'qty' => 10,
            'expiry_date' => $expiry->toDateString(),
        ])->assertCreated();
    }

    $body = $this->actingAs($tech, 'sanctum')
        ->postJson('/api/v1/stock/issues', [
            'item_id' => $item, 'qty' => 4, 'session_id' => $session->id,
        ])
        ->assertCreated()
        ->json();

    // FEFO: the lot nearest its expiry goes first, so the shelf does not quietly
    // accumulate stock that will be written off.
    expect($body['drawn_from'])->toHaveCount(1)
        ->and($body['drawn_from'][0]['lot_no'])->toBe('LOT-SOONER');

    expect((float) DB::table('stock_lots')->where('lot_no', 'LOT-SOONER')->value('qty_on_hand'))->toBe(6.0)
        ->and((float) DB::table('stock_lots')->where('lot_no', 'LOT-LATER')->value('qty_on_hand'))->toBe(10.0);
});

it('spans lots when one cannot cover the issue', function () {
    $item = reusableItem();
    $tech = biomed();
    $session = TreatmentSession::factory()->create();

    foreach ([['LOT-1', now()->addMonth(), 3], ['LOT-2', now()->addYear(), 10]] as [$lotNo, $expiry, $qty]) {
        $this->actingAs($tech, 'sanctum')->postJson('/api/v1/stock/receipts', [
            'item_id' => $item, 'lot_no' => $lotNo, 'qty' => $qty,
            'expiry_date' => $expiry->toDateString(),
        ])->assertCreated();
    }

    $body = $this->actingAs($tech, 'sanctum')
        ->postJson('/api/v1/stock/issues', ['item_id' => $item, 'qty' => 5, 'session_id' => $session->id])
        ->assertCreated()->json();

    expect($body['drawn_from'])->toHaveCount(2)
        ->and((float) DB::table('stock_lots')->where('lot_no', 'LOT-1')->value('qty_on_hand'))->toBe(0.0)
        ->and((float) DB::table('stock_lots')->where('lot_no', 'LOT-2')->value('qty_on_hand'))->toBe(8.0);
});

it('never issues from a quarantined lot', function () {
    $item = reusableItem();
    $tech = biomed();
    $session = TreatmentSession::factory()->create();

    $this->actingAs($tech, 'sanctum')->postJson('/api/v1/stock/receipts', [
        'item_id' => $item, 'lot_no' => 'LOT-RECALLED', 'qty' => 50,
        'expiry_date' => now()->addYear()->toDateString(),
    ])->assertCreated();

    // A recall hold. The stock is physically there and the count says so.
    DB::table('stock_lots')->where('lot_no', 'LOT-RECALLED')->update(['is_quarantined' => 1]);

    $response = $this->actingAs($tech, 'sanctum')
        ->postJson('/api/v1/stock/issues', ['item_id' => $item, 'qty' => 1, 'session_id' => $session->id]);

    // A hold that can be worked around is not a hold.
    $response->assertStatus(422);

    expect($response->json('message'))->toContain('short')
        ->and((float) DB::table('stock_lots')->where('lot_no', 'LOT-RECALLED')->value('qty_on_hand'))->toBe(50.0);
});

it('never issues expired stock', function () {
    $item = reusableItem();
    $tech = biomed();
    $session = TreatmentSession::factory()->create();

    DB::table('stock_lots')->insert([
        'item_id' => $item,
        'lot_no' => 'LOT-EXPIRED',
        'expiry_date' => now()->subDay()->toDateString(),
        'received_on' => now()->subYear()->toDateString(),
        'qty_received' => 20,
        'qty_on_hand' => 20,
    ]);

    // An expired bloodline is not a cost problem.
    $this->actingAs($tech, 'sanctum')
        ->postJson('/api/v1/stock/issues', ['item_id' => $item, 'qty' => 1, 'session_id' => $session->id])
        ->assertStatus(422);

    expect((float) DB::table('stock_lots')->where('lot_no', 'LOT-EXPIRED')->value('qty_on_hand'))->toBe(20.0);
});

it('issues nothing at all when the shelf cannot cover the whole request', function () {
    $item = reusableItem();
    $tech = biomed();
    $session = TreatmentSession::factory()->create();

    $this->actingAs($tech, 'sanctum')->postJson('/api/v1/stock/receipts', [
        'item_id' => $item, 'lot_no' => 'LOT-SMALL', 'qty' => 2,
        'expiry_date' => now()->addYear()->toDateString(),
    ])->assertCreated();

    $this->actingAs($tech, 'sanctum')
        ->postJson('/api/v1/stock/issues', ['item_id' => $item, 'qty' => 5, 'session_id' => $session->id])
        ->assertStatus(422);

    // Rolled back whole: a partial issue leaves the count right and the shelf wrong.
    expect((float) DB::table('stock_lots')->where('lot_no', 'LOT-SMALL')->value('qty_on_hand'))->toBe(2.0)
        ->and(DB::table('stock_transactions')->where('move_type', 'issue_to_session')->count())->toBe(0);
});

it('has a database backstop that refuses to take a lot below zero', function () {
    $item = reusableItem();

    $lotId = DB::table('stock_lots')->insertGetId([
        'item_id' => $item,
        'lot_no' => 'LOT-BACKSTOP',
        'received_on' => now()->toDateString(),
        'qty_received' => 5,
        'qty_on_hand' => 5,
    ]);

    // Straight at the table, as a stray script would. The trigger's UPDATE runs
    // into sl_qty_ck and the whole insert fails.
    expect(fn () => DB::table('stock_transactions')->insert([
        'lot_id' => $lotId,
        'item_id' => $item,
        'move_type' => 'adjustment',
        'qty' => -6,
        'occurred_at' => now(),
    ]))->toThrow(QueryException::class);

    expect((float) DB::table('stock_lots')->where('id', $lotId)->value('qty_on_hand'))->toBe(5.0);
});

it('reports on-hand stock through the view the dashboard reads', function () {
    $item = reusableItem();
    $tech = biomed();

    $this->actingAs($tech, 'sanctum')->postJson('/api/v1/stock/receipts', [
        'item_id' => $item, 'lot_no' => 'LOT-VIEW', 'qty' => 40,
        'expiry_date' => now()->addMonths(2)->toDateString(),
    ])->assertCreated();

    $body = $this->actingAs($tech, 'sanctum')->getJson('/api/v1/stock')->assertOk()->json();

    $row = collect($body['items'])->firstWhere('item_id', $item);

    expect((float) $row['qty_on_hand'])->toBe(40.0)
        // Expiring inside 90 days, which is what the reorder screen highlights.
        ->and((float) $row['expiring_90d'])->toBe(40.0);
});

it('stops issuing a lot at the unit s midnight on the day it expires', function () {
    unitIn('Asia/Manila');
    $item = reusableItem();
    $tech = biomed();
    $session = TreatmentSession::factory()->create();

    $this->travelTo(Carbon\Carbon::parse('2030-03-10 12:00:00', 'Asia/Manila'));

    $this->actingAs($tech, 'sanctum')->postJson('/api/v1/stock/receipts', [
        'item_id' => $item, 'lot_no' => 'LOT-EXPIRING', 'qty' => 10, 'expiry_date' => '2030-03-10',
    ])->assertCreated();

    // 01:00 on the 11th in Manila. By the UTC date it was still the 10th, and
    // the expired lot stayed issuable until 08:00.
    $this->travelTo(Carbon\Carbon::parse('2030-03-11 01:00:00', 'Asia/Manila'));

    $this->actingAs($tech, 'sanctum')
        ->postJson('/api/v1/stock/issues', ['item_id' => $item, 'qty' => 1, 'session_id' => $session->id])
        ->assertStatus(422);

    expect((float) DB::table('stock_lots')->where('lot_no', 'LOT-EXPIRING')->value('qty_on_hand'))->toBe(10.0);
});
