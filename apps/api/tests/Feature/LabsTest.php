<?php

declare(strict_types=1);

use App\Domain\Core\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Labs
|--------------------------------------------------------------------------
|
| Monthly bloods. What is protected here is mostly the interpretation: the
| abnormal flag is derived and never accepted, the dialysis target is kept
| separate from the laboratory reference interval, and the same result cannot be
| filed twice -- including in the NULL-timing case the baseline's own unique key
| silently allowed.
|
*/

/** @return array<string, mixed> */
function labRow(string $code, float|string|null $value, string $date = '2026-03-01', array $extra = []): array
{
    return [
        'test_code' => $code,
        'value_num' => $value,
        'specimen_date' => $date,
    ] + $extra;
}

/* ---------------------------------------------------------------- catalogue */

it('publishes the test catalogue with both ranges', function () {
    $body = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->getJson('/api/v1/lab-tests')
        ->assertOk()
        ->json('tests');

    $hgb = collect($body)->firstWhere('code', 'HGB');

    // Both ranges travel together. They are different questions and the screen
    // needs both to avoid teaching staff that red means nothing.
    expect($hgb)->not->toBeNull()
        ->and((float) $hgb['ref_low'])->toBe(12.0)
        ->and((float) $hgb['ref_high'])->toBe(16.0)
        ->and((float) $hgb['target_low'])->toBe(10.0)
        ->and((float) $hgb['target_high'])->toBe(11.5);
});

it('filters the catalogue by panel', function () {
    $quarterly = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->getJson('/api/v1/lab-tests?panel=quarterly')
        ->assertOk()
        ->json('tests');

    expect($quarterly)->not->toBeEmpty()
        ->and(collect($quarterly)->pluck('panel')->unique()->all())->toBe(['quarterly']);
});

/* ------------------------------------------------------------------ ordering */

it('lets a nephrologist order a panel but not a nurse', function () {
    $patient = Patient::factory()->create();

    $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/lab-orders", ['panel' => 'monthly'])
        ->assertStatus(403);

    $this->actingAs(staffWithRole('nephrologist'), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/lab-orders", ['panel' => 'monthly'])
        ->assertCreated()
        ->assertJsonPath('order.status', 'ordered');
});

it('refuses a panel that has no tests behind it', function () {
    $patient = Patient::factory()->create();

    $response = $this->actingAs(staffWithRole('nephrologist'), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/lab-orders", ['panel' => 'fortnightly']);

    // Otherwise the unit draws blood for a panel nothing will be filed against.
    $response->assertStatus(422);
    expect($response->json('message'))->toContain('draw blood for nothing');
});

it('walks an order through collection to results and then closes it', function () {
    $patient = Patient::factory()->create();
    $physician = staffWithRole('nephrologist');

    $orderId = $this->actingAs($physician, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/lab-orders", ['panel' => 'monthly'])
        ->json('order.id');

    $this->actingAs($physician, 'sanctum')
        ->postJson("/api/v1/lab-orders/{$orderId}/status", ['status' => 'collected'])
        ->assertOk()
        ->assertJsonPath('order.status', 'collected');

    expect(DB::table('lab_orders')->where('id', $orderId)->value('collected_at'))->not->toBeNull();

    $this->actingAs($physician, 'sanctum')
        ->postJson("/api/v1/lab-orders/{$orderId}/status", ['status' => 'resulted'])
        ->assertOk();

    // A resulted order is closed. Reopening it would let a second set of values
    // attach to a panel a clinician has already read.
    $response = $this->actingAs($physician, 'sanctum')
        ->postJson("/api/v1/lab-orders/{$orderId}/status", ['status' => 'collected']);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('already resulted');
});

it('refuses to skip collection', function () {
    $patient = Patient::factory()->create();
    $physician = staffWithRole('nephrologist');

    $orderId = $this->actingAs($physician, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/lab-orders", ['panel' => 'monthly'])
        ->json('order.id');

    $response = $this->actingAs($physician, 'sanctum')
        ->postJson("/api/v1/lab-orders/{$orderId}/status", ['status' => 'resulted']);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('can only become collected or cancelled');
});

/* ------------------------------------------------------------------- filing */

it('derives the abnormal flag from the reference interval', function () {
    $patient = Patient::factory()->create();

    $body = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/lab-results", [
            'results' => [
                labRow('HGB', 9.0),                            // below ref 12-16
                labRow('WBC', 14.0),                           // above ref 4-11
                labRow('PLT', 250.0),                          // inside ref 150-400
                labRow('CREA', 800.0),                         // no interval at all
            ],
        ])
        ->assertOk()
        ->json();

    $flags = collect($body['results'])->pluck('abnormal_flag', 'test_code');

    expect($body['filed'])->toBe(4)
        ->and($flags['HGB'])->toBe('L')
        ->and($flags['WBC'])->toBe('H')
        ->and($flags['PLT'])->toBe('N')
        // A flag on a test with no reference interval would be decoration.
        ->and($flags['CREA'])->toBeNull();
});

it('keeps the dialysis target separate from the laboratory reference interval', function () {
    $patient = Patient::factory()->create();

    $body = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/lab-results", [
            'results' => [labRow('HGB', 11.0)],
        ])
        ->assertOk()
        ->json('results.0');

    // 11.0 g/dL is low against the general reference of 12-16 and exactly where
    // a dialysed patient should be (target 10-11.5). Reporting only the first
    // number is how a unit learns to ignore red.
    expect($body['abnormal_flag'])->toBe('L')
        ->and($body['on_target'])->toBeTrue();
});

it('never stores an abnormal flag the caller supplied', function () {
    $patient = Patient::factory()->create();

    $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/lab-results", [
            // A platelet count squarely inside its reference interval, labelled
            // critically high by the caller.
            'results' => [labRow('PLT', 250.0, '2026-03-01', ['abnormal_flag' => 'HH'])],
        ])
        ->assertOk();

    expect(DB::table('lab_results')->where('test_code', 'PLT')->value('abnormal_flag'))->toBe('N');
});

it('never invents a critical flag', function () {
    $patient = Patient::factory()->create();

    // Wildly out of range in both directions. LL and HH exist in the schema for
    // a laboratory feed that supplies them; "critically abnormal" is a cut-off
    // that is not in the reference data, so this system does not guess one.
    $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/lab-results", [
            'results' => [labRow('K', 9.9), labRow('HGB', 1.0)],
        ])
        ->assertOk();

    expect(DB::table('lab_results')->pluck('abnormal_flag')->unique()->sort()->values()->all())
        ->toBe(['H', 'L']);
});

it('adjudicates each row so one bad code does not reject the panel', function () {
    $patient = Patient::factory()->create();

    $body = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/lab-results", [
            'results' => [
                labRow('HGB', 10.5),
                labRow('HGBB', 10.5),          // typo
                labRow('ALB', null),           // no value at all
                labRow('K', 4.2),
            ],
        ])
        ->assertOk()
        ->json();

    $byCode = collect($body['results'])->keyBy('test_code');

    expect($body['filed'])->toBe(2)
        ->and($byCode['HGB']['status'])->toBe('filed')
        ->and($byCode['K']['status'])->toBe('filed')
        ->and($byCode['HGBB']['status'])->toBe('rejected')
        ->and($byCode['HGBB']['message'])->toContain('not a test in the catalogue')
        ->and($byCode['ALB']['status'])->toBe('rejected')
        ->and($byCode['ALB']['message'])->toContain('not a result');
});

it('refuses the same result twice even when no timing is given', function () {
    $patient = Patient::factory()->create();
    $nurse = staffWithRole('nurse');

    $this->actingAs($nurse, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/lab-results", [
            'results' => [labRow('HGB', 10.5)],
        ])->assertOk();

    $second = $this->actingAs($nurse, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/lab-results", [
            'results' => [labRow('HGB', 99.9)],
        ])->assertOk()->json('results.0');

    // The baseline's own unique key used `timing` directly, and MySQL ignores
    // NULLs in a unique index -- so this pair was accepted, leaving two
    // different haemoglobins for one specimen. The generated timing_key closes
    // it. See the 2026_08_21 migration.
    expect($second['status'])->toBe('duplicate')
        ->and(DB::table('lab_results')->where('test_code', 'HGB')->count())->toBe(1)
        ->and((float) DB::table('lab_results')->where('test_code', 'HGB')->value('value_num'))->toBe(10.5);
});

it('treats a pre-HD and post-HD sample of the same test as different results', function () {
    $patient = Patient::factory()->create();

    $body = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/lab-results", [
            'results' => [
                labRow('BUN_PRE', 22.0, '2026-03-01', ['timing' => 'pre_hd']),
                labRow('BUN_POST', 7.0, '2026-03-01', ['timing' => 'post_hd']),
            ],
        ])
        ->assertOk()
        ->json();

    expect($body['filed'])->toBe(2)
        ->and(DB::table('lab_results')->count())->toBe(2);
});

it('fills the unit from the catalogue when the caller omits it', function () {
    $patient = Patient::factory()->create();

    $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/lab-results", [
            'results' => [labRow('IPTH', 320.0)],
        ])->assertOk();

    expect(DB::table('lab_results')->where('test_code', 'IPTH')->value('unit'))->toBe('pg/mL');
});

it('closes the order when its results are filed', function () {
    $patient = Patient::factory()->create();
    $physician = staffWithRole('nephrologist');

    $orderId = $this->actingAs($physician, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/lab-orders", ['panel' => 'monthly'])
        ->json('order.id');

    $this->actingAs($physician, 'sanctum')
        ->postJson("/api/v1/lab-orders/{$orderId}/status", ['status' => 'collected'])->assertOk();

    $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/lab-results", [
            'order_id' => $orderId,
            'results' => [labRow('HGB', 10.5), labRow('K', 4.6)],
        ])->assertOk();

    expect(DB::table('lab_orders')->where('id', $orderId)->value('status'))->toBe('resulted')
        ->and(DB::table('lab_results')->where('order_id', $orderId)->count())->toBe(2);
});

it('refuses to file against another patient\'s order', function () {
    $mine = Patient::factory()->create();
    $theirs = Patient::factory()->create();

    $orderId = $this->actingAs(staffWithRole('nephrologist'), 'sanctum')
        ->postJson("/api/v1/patients/{$theirs->public_id}/lab-orders", ['panel' => 'monthly'])
        ->json('order.id');

    $response = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson("/api/v1/patients/{$mine->public_id}/lab-results", [
            'order_id' => $orderId,
            'results' => [labRow('HGB', 10.5)],
        ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('different patient');
});

/* ------------------------------------------------------------------ reading */

it('returns the latest value per test for a chart', function () {
    $patient = Patient::factory()->create();
    $nurse = staffWithRole('nurse');

    foreach ([['2026-01-01', 9.0], ['2026-02-01', 10.0], ['2026-03-01', 11.0]] as [$date, $value]) {
        $this->actingAs($nurse, 'sanctum')
            ->postJson("/api/v1/patients/{$patient->public_id}/lab-results", [
                'results' => [labRow('HGB', $value, $date), labRow('K', 4.0, $date)],
            ])->assertOk();
    }

    $all = $this->actingAs($nurse, 'sanctum')
        ->getJson("/api/v1/patients/{$patient->public_id}/lab-results")
        ->assertOk()->json('results');

    $latest = $this->actingAs($nurse, 'sanctum')
        ->getJson("/api/v1/patients/{$patient->public_id}/lab-results?latest=1")
        ->assertOk()->json('results');

    expect($all)->toHaveCount(6)
        ->and($latest)->toHaveCount(2)
        ->and(collect($latest)->firstWhere('test_code', 'HGB')['specimen_date'])->toBe('2026-03-01')
        ->and((float) collect($latest)->firstWhere('test_code', 'HGB')['value_num'])->toBe(11.0);
});

it('carries the ranges alongside each result', function () {
    $patient = Patient::factory()->create();
    $nurse = staffWithRole('nurse');

    $this->actingAs($nurse, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/lab-results", [
            'results' => [labRow('FER', 150.0)],
        ])->assertOk();

    $row = $this->actingAs($nurse, 'sanctum')
        ->getJson("/api/v1/patients/{$patient->public_id}/lab-results")
        ->assertOk()->json('results.0');

    // Attached to the result rather than looked up by the client, so a screen
    // cannot pair this month's value with next year's reference interval.
    expect($row['name'])->toBe('Ferritin')
        ->and($row['unit'])->toBe('ng/mL')
        ->and((float) $row['ref_low'])->toBe(30.0)
        ->and((float) $row['target_low'])->toBe(200.0)
        ->and($row['abnormal_flag'])->toBe('N')       // inside ref 30-400
        ->and($row['on_target'])->toBeFalse();        // below target 200-800
});

it('filters history to one test for trending', function () {
    $patient = Patient::factory()->create();
    $nurse = staffWithRole('nurse');

    $this->actingAs($nurse, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/lab-results", [
            'results' => [labRow('HGB', 10.5), labRow('K', 4.2), labRow('ALB', 38.0)],
        ])->assertOk();

    $body = $this->actingAs($nurse, 'sanctum')
        ->getJson("/api/v1/patients/{$patient->public_id}/lab-results?test_code=HGB")
        ->assertOk()->json('results');

    expect($body)->toHaveCount(1)->and($body[0]['test_code'])->toBe('HGB');
});

it('counts only rows that were actually written', function () {
    $patient = Patient::factory()->create();
    $nurse = staffWithRole('nurse');

    $this->actingAs($nurse, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/lab-results", [
            'results' => [labRow('HGB', 10.5)],
        ])->assertOk();

    $body = $this->actingAs($nurse, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/lab-results", [
            'results' => [
                labRow('HGB', 10.5),      // already filed
                labRow('ALB', 38.0),      // new
                labRow('NOPE', 1.0),      // not a test
            ],
        ])->assertOk()->json();

    // A duplicate is neither an error nor a write. Counting it would report
    // two filed for one stored row -- and the same counter decides whether an
    // order gets closed.
    expect($body['filed'])->toBe(1)
        ->and($body['duplicates'])->toBe(1)
        ->and($body['rejected'])->toBe(1)
        ->and(DB::table('lab_results')->count())->toBe(2);
});

it('leaves an order open when the whole batch was already filed', function () {
    $patient = Patient::factory()->create();
    $physician = staffWithRole('nephrologist');
    $nurse = staffWithRole('nurse');

    $orderId = $this->actingAs($physician, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/lab-orders", ['panel' => 'monthly'])
        ->json('order.id');

    $this->actingAs($physician, 'sanctum')
        ->postJson("/api/v1/lab-orders/{$orderId}/status", ['status' => 'collected'])->assertOk();

    // File the panel elsewhere first, so re-sending it against the order is all
    // duplicates and writes nothing.
    $this->actingAs($nurse, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/lab-results", [
            'results' => [labRow('HGB', 10.5)],
        ])->assertOk();

    $this->actingAs($nurse, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/lab-results", [
            'order_id' => $orderId,
            'results' => [labRow('HGB', 10.5)],
        ])->assertOk();

    // Nothing was written, so the order has not been resulted.
    expect(DB::table('lab_orders')->where('id', $orderId)->value('status'))->toBe('collected');
});
