<?php

declare(strict_types=1);

use App\Domain\Core\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Auditing and record access
|--------------------------------------------------------------------------
| Two different questions, deliberately answered by two different tables:
|
|   audit_logs         -- who CHANGED what, with before/after
|   record_access_logs -- who OPENED whose chart, whether or not they changed it
|
| The second is the one privacy regulators ask about, and it is the one that is
| easy to forget because a read leaves no other trace.
|
| MySQL cannot serialise a row to JSON inside a trigger, so unlike the original
| PostgreSQL design the audit trail is written in PHP by AuditObserver. The
| consequence is worth restating: a direct SQL UPDATE bypasses all of this, which
| is why binlog_format=ROW is the independent record.
*/

uses(TestCase::class, RefreshDatabase::class);

it('writes an audit row with before and after data when a patient is updated', function () {
    $patient = Patient::factory()->create(['last_name' => 'Reyes']);

    DB::table('audit_logs')->delete(); // ignore the INSERT from creating the patient

    $patient->update(['last_name' => 'Reyes-Santos']);

    $entry = DB::table('audit_logs')
        ->where('auditable_type', Patient::class)
        ->where('auditable_id', $patient->id)
        ->where('action', 'UPDATE')
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull();

    $before = json_decode((string) $entry->before_data, true, 512, JSON_THROW_ON_ERROR);
    $after = json_decode((string) $entry->after_data, true, 512, JSON_THROW_ON_ERROR);
    $changed = json_decode((string) $entry->changed_cols, true, 512, JSON_THROW_ON_ERROR);

    expect($before)->toBe(['last_name' => 'Reyes'])
        ->and($after)->toBe(['last_name' => 'Reyes-Santos'])
        ->and($changed)->toBe(['last_name']);
});

it('never names a column in changed_cols that the payload does not carry', function () {
    // updated_at is dirty or not depending on whether this write landed in the
    // same millisecond as the last one -- DATETIME(3). It is excluded from the
    // payloads, so it must be excluded from changed_cols too, or the audit row
    // contradicts itself and does so intermittently.
    $patient = Patient::factory()->create(['last_name' => 'Ocampo']);

    DB::table('audit_logs')->delete();

    $patient->update(['last_name' => 'Ocampo-Diaz', 'city' => 'Davao']);

    $entry = DB::table('audit_logs')->where('action', 'UPDATE')->latest('id')->firstOrFail();

    $changed = json_decode((string) $entry->changed_cols, true, 512, JSON_THROW_ON_ERROR);
    $after = json_decode((string) $entry->after_data, true, 512, JSON_THROW_ON_ERROR);

    expect($changed)->not->toContain('updated_at')
        ->and(array_keys($after))->not->toContain('updated_at')
        // Every named column is present in the payload.
        ->and(array_diff($changed, array_keys($after)))->toBe([])
        ->and($changed)->toContain('last_name')
        ->and($changed)->toContain('city');
});

it('does not write an audit row when only a timestamp moved', function () {
    $patient = Patient::factory()->create();

    DB::table('audit_logs')->delete();

    $patient->touch();

    expect(DB::table('audit_logs')->where('action', 'UPDATE')->count())->toBe(0);
});

it('names the actor who made the change', function () {
    $nurse = staffWithRole('nurse');
    $patient = Patient::factory()->create(['last_name' => 'Cruz']);

    $this->actingAs($nurse, 'sanctum');

    $patient->update(['last_name' => 'Cruz-Lim']);

    $entry = DB::table('audit_logs')
        ->where('auditable_id', $patient->id)
        ->where('action', 'UPDATE')
        ->latest('id')
        ->first();

    expect($entry->actor_id)->toBe($nurse->id)
        ->and($entry->actor_name)->toBe($nurse->full_name);
});

it('never writes a credential into the audit payload', function () {
    $nurse = staffWithRole('nurse');

    DB::table('audit_logs')->delete();

    // Staff is not itself audited in Phase 0, so assert the exclusion list that
    // protects every audited model rather than the absence of a row.
    expect((new Patient)->auditExclude())
        ->toContain('password')
        ->toContain('clinical_pin_hash')
        ->toContain('two_factor_secret');
});

it('does not write an audit row when nothing actually changed', function () {
    $patient = Patient::factory()->create(['last_name' => 'Dela Cruz']);

    DB::table('audit_logs')->delete();

    $patient->update(['last_name' => 'Dela Cruz']);

    expect(DB::table('audit_logs')->where('action', 'UPDATE')->count())->toBe(0);
});

it('logs a chart view to record_access_logs', function () {
    $nurse = staffWithRole('nurse');
    $patient = Patient::factory()->create();

    $response = $this->actingAs($nurse, 'sanctum')
        ->getJson("/api/v1/patients/{$patient->public_id}");

    $response->assertOk()->assertJsonPath('public_id', $patient->public_id);

    $access = DB::table('record_access_logs')
        ->where('patient_id', $patient->id)
        ->latest('id')
        ->first();

    expect($access)->not->toBeNull()
        ->and($access->actor_id)->toBe($nurse->id)
        ->and($access->context)->toBe('patients.show');
});

it('never exposes the internal row id in a patient payload', function () {
    $patient = Patient::factory()->create();

    $body = $this->actingAs(staffWithRole('nurse'), 'sanctum')
        ->getJson("/api/v1/patients/{$patient->public_id}")
        ->assertOk()
        ->json();

    expect($body)->not->toHaveKey('id')
        ->and($body['public_id'])->toBe($patient->public_id);
});

it('does not log a chart view that was refused', function () {
    $patient = Patient::factory()->create();

    // No token at all: the request never reaches the policy, and an unauthorised
    // probe must not be able to confirm a patient exists by leaving a log row.
    $this->getJson("/api/v1/patients/{$patient->public_id}")->assertUnauthorized();

    expect(DB::table('record_access_logs')->where('patient_id', $patient->id)->count())->toBe(0);
});
