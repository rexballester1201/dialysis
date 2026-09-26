<?php

declare(strict_types=1);

use App\Domain\Clinical\Models\TreatmentSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Build a realistic offline shift: header plus half-hourly observations. */
function offlineShift(TreatmentSession $session, int $vitals = 9): array
{
    $ops = [[
        'op_uuid' => strtoupper((string) Str::ulid()),
        'type' => 'session.upsert',
        'payload' => [
            'session_public_id' => $session->public_id,
            // Observations only. This fixture once also sent dry_weight_kg, which
            // encoded the very hole UpsertSessionHandler now refuses: dry weight is
            // effective-dated and snapshotted at check-in, never set by a tablet.
            'pre_weight_kg' => 61.2, 'pre_bp_sys' => 148,
        ],
    ]];

    for ($i = 0; $i < $vitals; $i++) {
        $ops[] = [
            'op_uuid' => strtoupper((string) Str::ulid()),
            'type' => 'vital.append',
            'payload' => [
                'session_public_id' => $session->public_id,
                'recorded_at' => now()->subMinutes(255 - $i * 30)->format('Y-m-d H:i:s'),
                'minutes_elapsed' => $i * 30,
                'bp_sys' => 148 - $i * 5, 'bp_dia' => 84 - $i * 2, 'pulse' => 80 + $i,
            ],
        ];
    }

    return $ops;
}

it('applies a full offline shift in one batch', function () {
    $session = TreatmentSession::factory()->inProgress()->create();
    $ops = offlineShift($session);

    $response = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson('/api/v1/sync', [
            'batch_uuid' => strtoupper((string) Str::ulid()),
            'device_id' => 'TABLET-01',
            'operations' => $ops,
        ]);

    $response->assertOk();
    expect(collect($response->json('results'))->where('status', 'applied'))->toHaveCount(10)
        ->and($session->vitals()->count())->toBe(9);
});

it('is idempotent when the same batch is retried', function () {
    $session = TreatmentSession::factory()->inProgress()->create();
    $batchUuid = strtoupper((string) Str::ulid());
    $payload = ['batch_uuid' => $batchUuid, 'device_id' => 'TABLET-01', 'operations' => offlineShift($session)];
    $nurse = staffWithRole('nurse');

    $first = $this->actingAs($nurse, 'sanctum')->postJson('/api/v1/sync', $payload);
    $second = $this->actingAs($nurse, 'sanctum')->postJson('/api/v1/sync', $payload);

    expect($second->json('replayed'))->toBeTrue()
        ->and($second->json('results'))->toEqual($first->json('results'))
        ->and($session->vitals()->count())->toBe(9);
});

it('deduplicates operations resent under a new batch id', function () {
    // The tablet sent a batch, the ack was lost, so it retries with a new
    // batch_uuid but the same op_uuids. Nothing may be written twice.
    $session = TreatmentSession::factory()->inProgress()->create();
    $ops = offlineShift($session);
    $nurse = staffWithRole('nurse');

    foreach ([1, 2] as $attempt) {
        $response = $this->actingAs($nurse, 'sanctum')->postJson('/api/v1/sync', [
            'batch_uuid' => strtoupper((string) Str::ulid()),
            'device_id' => 'TABLET-01',
            'operations' => $ops,
        ]);

        if ($attempt === 2) {
            expect(collect($response->json('results'))->where('status', 'duplicate'))->toHaveCount(9);
        }
    }

    expect($session->vitals()->count())->toBe(9);
});

it('does not lose a whole batch to one malformed operation', function () {
    $session = TreatmentSession::factory()->inProgress()->create();

    $response = $this->actingAs(staffWithRole('nurse'), 'sanctum')->postJson('/api/v1/sync', [
        'batch_uuid' => strtoupper((string) Str::ulid()),
        'device_id' => 'TABLET-01',
        'operations' => [
            ['op_uuid' => strtoupper((string) Str::ulid()), 'type' => 'vital.append', 'payload' => [
                'session_public_id' => $session->public_id,
                'recorded_at' => now()->format('Y-m-d H:i:s'), 'bp_sys' => 118,
            ]],
            ['op_uuid' => strtoupper((string) Str::ulid()), 'type' => 'vital.append', 'payload' => [
                'session_public_id' => 'DOES-NOT-EXIST-000000000',
            ]],
            ['op_uuid' => strtoupper((string) Str::ulid()), 'type' => 'nonsense.op', 'payload' => []],
        ],
    ]);

    $statuses = collect($response->json('results'))->pluck('status')->all();

    expect($statuses)->toBe(['applied', 'rejected', 'rejected'])
        ->and($session->vitals()->count())->toBe(1);
});

it('reports a conflict when the session was signed while offline', function () {
    $session = TreatmentSession::factory()->locked()->create();

    $response = $this->actingAs(staffWithRole('nurse'), 'sanctum')->postJson('/api/v1/sync', [
        'batch_uuid' => strtoupper((string) Str::ulid()),
        'device_id' => 'TABLET-01',
        'operations' => [[
            'op_uuid' => strtoupper((string) Str::ulid()),
            'type' => 'vital.append',
            'payload' => [
                'session_public_id' => $session->public_id,
                'recorded_at' => now()->format('Y-m-d H:i:s'), 'bp_sys' => 120,
            ],
        ]],
    ]);

    expect($response->json('results.0.status'))->toBe('conflict')
        ->and($session->vitals()->count())->toBe(0);
});

it('keeps the outbox when the token expired offline', function () {
    // A 401 must never be treated as "operation failed permanently"; the
    // client re-authenticates by PIN and replays the same batch.
    $session = TreatmentSession::factory()->inProgress()->create();

    $this->postJson('/api/v1/sync', [
        'batch_uuid' => strtoupper((string) Str::ulid()),
        'device_id' => 'TABLET-01',
        'operations' => offlineShift($session, 1),
    ])->assertUnauthorized();

    expect(DB::table('sync_batches')->count())->toBe(0);
});

/* -------------------------------------------------------------------------- */
/* event.append -- the bedside flow sheet charts events as well as vitals */
/* -------------------------------------------------------------------------- */

function offlineEvent(TreatmentSession $session, string $code = 'hypotension'): array
{
    return [
        'op_uuid' => strtoupper((string) Str::ulid()),
        'type' => 'event.append',
        'payload' => [
            'session_public_id' => $session->public_id,
            'occurred_at' => now()->subMinutes(90)->format('Y-m-d H:i:s'),
            'event_code' => $code,
            'severity' => 'moderate',
            'description' => 'BP 78/44 at 90 minutes, patient lightheaded.',
            'intervention' => '200 ml normal saline, UF paused, Trendelenburg.',
        ],
    ];
}

it('charts an event recorded while the tablet was offline', function () {
    $session = TreatmentSession::factory()->inProgress()->create();
    $op = offlineEvent($session);

    $response = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson('/api/v1/sync', [
            'batch_uuid' => strtoupper((string) Str::ulid()),
            'device_id' => 'TABLET-01',
            'operations' => [$op],
        ]);

    $response->assertOk();
    expect($response->json('results.0.status'))->toBe('applied');

    // The client_uuid is the whole idempotency story -- it must be persisted.
    $event = DB::table('session_events')->where('session_id', $session->id)->first();
    expect($event->event_code)->toBe('hypotension')
        ->and($event->client_uuid)->toBe($op['op_uuid'])
        ->and($event->severity)->toBe('moderate');
});

it('reports a duplicate rather than charting the same event twice', function () {
    $session = TreatmentSession::factory()->inProgress()->create();
    $op = offlineEvent($session);
    $nurse = staffWithRole('nurse');

    // Same operation, two different batches: the network retried under a new id.
    foreach ([1, 2] as $attempt) {
        $response = $this->actingAs($nurse, 'sanctum')->postJson('/api/v1/sync', [
            'batch_uuid' => strtoupper((string) Str::ulid()),
            'device_id' => 'TABLET-01',
            'operations' => [$op],
        ]);

        expect($response->json('results.0.status'))->toBe($attempt === 1 ? 'applied' : 'duplicate');
    }

    expect(DB::table('session_events')->where('session_id', $session->id)->count())->toBe(1);
});

it('rejects an unrecognised event code without sinking the batch', function () {
    $session = TreatmentSession::factory()->inProgress()->create();

    $response = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson('/api/v1/sync', [
            'batch_uuid' => strtoupper((string) Str::ulid()),
            'device_id' => 'TABLET-01',
            'operations' => [offlineEvent($session, 'hypotensionn'), offlineEvent($session)],
        ]);

    $response->assertOk();

    // The typo is refused; the well-formed event beside it still charts.
    expect($response->json('results.0.status'))->toBe('rejected')
        ->and($response->json('results.1.status'))->toBe('applied')
        ->and(DB::table('session_events')->where('session_id', $session->id)->count())->toBe(1);
});

it('reports a conflict when an event arrives after the session was signed', function () {
    // Signing is not a status -- it sets locked_at while the status stays
    // completed. The factory state is the definition of record for that.
    $session = TreatmentSession::factory()->locked()->create();
    $op = offlineEvent($session);

    $response = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson('/api/v1/sync', [
            'batch_uuid' => strtoupper((string) Str::ulid()),
            'device_id' => 'TABLET-01',
            'operations' => [$op],
        ]);

    // Not discarded, not forced through: reported back so the nurse can see it.
    expect($response->json('results.0.status'))->toBe('conflict')
        ->and($response->json('results.0.message'))->toContain('signed and locked')
        ->and(DB::table('session_events')->where('session_id', $session->id)->count())->toBe(0);
});

/* -------------------------------------------------------------------------- */
/* session.upsert is not a side door around the lifecycle */
/* -------------------------------------------------------------------------- */
/*
 | An earlier handler forceFilled status, started_at, dialyzer_unit_id, ktv,
 | urr_pct, dry_weight_kg and primary_nurse_id with no checks at all. A payload
 | carrying `status: in_progress` started a treatment without the water check,
 | the cohort check, the machine check or the dialyzer check. Each case below is
 | one way that used to work.
 */

function upsertOp(TreatmentSession $session, array $fields): array
{
    return [
        'op_uuid' => strtoupper((string) Str::ulid()),
        'type' => 'session.upsert',
        'payload' => ['session_public_id' => $session->public_id] + $fields,
    ];
}

function syncOne(TreatmentSession $session, array $fields): array
{
    return test()->actingAs(staffWithRole('nurse'), 'sanctum')
        ->postJson('/api/v1/sync', [
            'batch_uuid' => strtoupper((string) Str::ulid()),
            'device_id' => 'TABLET-01',
            'operations' => [upsertOp($session, $fields)],
        ])
        ->assertOk()
        ->json('results.0');
}

it('refuses to start a session offline, bypassing the water and cohort checks', function () {
    // No water check logged today. Online, start() would refuse outright.
    $session = TreatmentSession::factory()->create(['status' => 'checked_in', 'pre_weight_kg' => 61.0]);

    $result = syncOne($session, ['status' => 'in_progress', 'started_at' => now()->format('Y-m-d H:i:s')]);

    expect($result['status'])->toBe('rejected')
        ->and($result['message'])->toContain('lifecycle changes')
        ->and($result['message'])->toContain('status')
        ->and(DB::table('treatment_sessions')->where('id', $session->id)->value('status'))->toBe('checked_in')
        ->and(DB::table('treatment_sessions')->where('id', $session->id)->value('started_at'))->toBeNull();
});

it('refuses to issue a dialyzer offline, bypassing the reuse checks', function () {
    $session = TreatmentSession::factory()->inProgress()->create();
    // Condemned: below 80% TCV. Online, invariant 8 refuses it at the point of issue.
    $unit = dialyzerFor($session->patient, ['current_tcv_ml' => '80.0']);

    $result = syncOne($session, ['dialyzer_unit_id' => $unit->id]);

    expect($result['status'])->toBe('rejected')
        ->and($result['message'])->toContain('dialyzer issue')
        ->and(DB::table('treatment_sessions')->where('id', $session->id)->value('dialyzer_unit_id'))->toBeNull();
});

it('refuses a client-supplied Kt/V or URR offline', function () {
    $session = TreatmentSession::factory()->inProgress()->create();

    $result = syncOne($session, ['ktv' => 1.9, 'urr_pct' => 80]);

    // Derived values are the server's to compute, from the samples, at end().
    expect($result['status'])->toBe('rejected')
        ->and($result['message'])->toContain('adequacy values')
        ->and(DB::table('treatment_sessions')->where('id', $session->id)->value('ktv'))->toBeNull();
});

it('refuses to overwrite the snapshotted dry weight offline', function () {
    $session = TreatmentSession::factory()->inProgress()->create(['dry_weight_kg' => 57.5]);

    $result = syncOne($session, ['dry_weight_kg' => 50.0]);

    // IDWG is generated from pre_weight - dry_weight. Rewriting the dry weight
    // silently rewrites this session's fluid numbers.
    expect($result['status'])->toBe('rejected')
        ->and((float) DB::table('treatment_sessions')->where('id', $session->id)->value('dry_weight_kg'))->toBe(57.5);
});

it('refuses to reassign who did the work offline', function () {
    $session = TreatmentSession::factory()->inProgress()->create();
    $before = DB::table('treatment_sessions')->where('id', $session->id)->value('primary_nurse_id');
    $other = staffWithRole('nurse');

    $result = syncOne($session, ['primary_nurse_id' => $other->id]);

    expect($result['status'])->toBe('rejected')
        ->and($result['message'])->toContain('attribution')
        ->and(DB::table('treatment_sessions')->where('id', $session->id)->value('primary_nurse_id'))->toBe($before);
});

it('refuses a whole operation rather than quietly trimming it', function () {
    $session = TreatmentSession::factory()->create(['status' => 'checked_in']);

    // A legitimate observation beside a refused field. Applying the weight and
    // dropping the status would let the tablet believe it had started the
    // session. Nothing is applied, and the message says why.
    $result = syncOne($session, ['pre_weight_kg' => 62.0, 'status' => 'in_progress']);

    expect($result['status'])->toBe('rejected')
        ->and(DB::table('treatment_sessions')->where('id', $session->id)->value('pre_weight_kg'))->toBeNull();
});

it('reports a conflict when the treatment was ended while offline', function () {
    $session = TreatmentSession::factory()->completed()->create();
    $ktvBefore = DB::table('treatment_sessions')->where('id', $session->id)->value('ktv');

    $result = syncOne($session, ['post_weight_kg' => 55.0]);

    // end() derived adequacy from the values present then; changing an input
    // now would leave a stored Kt/V that disagrees with its own inputs.
    expect($result['status'])->toBe('conflict')
        ->and($result['message'])->toContain('ended while this device was offline')
        ->and(DB::table('treatment_sessions')->where('id', $session->id)->value('ktv'))->toBe($ktvBefore);
});

it('still applies observations and delivered settings offline', function () {
    $session = TreatmentSession::factory()->inProgress()->create();

    $result = syncOne($session, [
        'pre_bp_sys' => 152, 'pre_pulse' => 84,
        'blood_flow_set_ml_min' => 320, 'dialysate_temp_c' => 36.5,
        'post_weight_kg' => 58.4, 'net_uf_ml' => 2600,
    ]);

    $row = DB::table('treatment_sessions')->where('id', $session->id)->first();

    expect($result['status'])->toBe('applied')
        ->and((int) $row->pre_bp_sys)->toBe(152)
        ->and((int) $row->blood_flow_set_ml_min)->toBe(320)
        ->and((float) $row->post_weight_kg)->toBe(58.4)
        ->and((int) $row->net_uf_ml)->toBe(2600);
});

it('hands a tablet this morning s board when it asks without a date', function () {
    unitIn('Asia/Manila');

    // 06:00 in Manila on the 11th; still the 10th in UTC.
    $this->travelTo(Carbon\Carbon::parse('2030-03-11 06:00:00', 'Asia/Manila'));

    $today = TreatmentSession::factory()->create(['session_date' => '2030-03-11']);
    TreatmentSession::factory()->create(['session_date' => '2030-03-10']);

    $body = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->getJson('/api/v1/sync/bootstrap')
        ->assertOk()
        ->json();

    // Defaulting to the server's UTC date handed the morning shift yesterday's board.
    expect($body['date'])->toBe('2030-03-11')
        ->and(array_column($body['sessions'], 'public_id'))->toBe([$today->public_id]);
});
