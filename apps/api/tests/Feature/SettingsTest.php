<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** The haemodialysis programme in force today, from the reference seed. */
function currentHdProgram(): object
{
    $program = DB::table('benefit_programs')
        ->where('modality', 'hd')
        ->whereNull('effective_to')
        ->orderByDesc('effective_from')
        ->first();

    expect($program)->not->toBeNull();

    return $program;
}

/*
|--------------------------------------------------------------------------
| Unit settings
|--------------------------------------------------------------------------
|
| Three of these change what the system will let a nurse do to a patient, so
| what is tested here is mostly the guards: who may change them, that an audit
| entry exists afterwards, and that the dangerous shapes are either refused or
| recorded rather than applied quietly.
|
*/

it('refuses settings to anyone who is not an administrator', function () {
    foreach (['nurse', 'billing', 'technician'] as $role) {
        $this->actingAs(staffWithRole($role), 'sanctum')
            ->getJson('/api/v1/settings')
            ->assertStatus(403);
    }

    $this->actingAs(staffWithRole('admin'), 'sanctum')
        ->getJson('/api/v1/settings')
        ->assertOk()
        ->assertJsonStructure(['facility', 'stations', 'benefit_programs', 'high_alert', 'staff', 'roles']);
});

it('records who cleared a chair of its cohort restriction', function () {
    $admin = staffWithRole('admin');
    $station = DB::table('stations')->first();
    DB::table('station_cohorts')->where('station_id', $station->id)->delete();
    DB::table('station_cohorts')->insert(['station_id' => $station->id, 'cohort' => 'hbv']);

    $this->actingAs($admin, 'sanctum')
        ->putJson("/api/v1/settings/stations/{$station->id}/cohorts", ['cohorts' => []])
        ->assertOk();

    // An empty list is allowed -- a unit may genuinely have a general-purpose
    // chair -- but it widens infection-control segregation, so it must leave a
    // trace naming the person who did it.
    $entry = DB::table('audit_logs')
        ->where('auditable_type', 'table:station_cohorts')
        ->orderByDesc('id')->first();

    expect($entry)->not->toBeNull()
        ->and((int) $entry->actor_id)->toBe((int) $admin->id)
        ->and(json_decode($entry->before_data, true)['cohorts'])->toBe(['hbv'])
        ->and(json_decode($entry->after_data, true)['cohorts'])->toBe([]);

    // And the chair now genuinely accepts anyone, which is what was recorded.
    expect(DB::table('station_cohorts')->where('station_id', $station->id)->count())->toBe(0);
});

it('refuses a cohort that is not an infection-control cohort', function () {
    $station = DB::table('stations')->first();

    $this->actingAs(staffWithRole('admin'), 'sanctum')
        ->putJson("/api/v1/settings/stations/{$station->id}/cohorts", ['cohorts' => ['clean', 'measles']])
        ->assertStatus(422);
});

it('supersedes a benefit programme instead of editing it', function () {
    $admin = staffWithRole('admin');
    $existing = currentHdProgram();

    $this->actingAs($admin, 'sanctum')->postJson('/api/v1/settings/benefit-programs', [
        'payer_id' => $existing->payer_id,
        'code' => 'PH_HD_2027',
        'name' => 'PhilHealth HD case rate (new circular)',
        'modality' => 'hd',
        'case_rate' => '6350.00',
        'sessions_per_period' => 156,
        'period_kind' => 'calendar_year',
        'currency' => 'PHP',
        'no_balance_billing' => true,
        'effective_from' => '2027-01-01',
        'circular_ref' => 'PC-2026-0099',
    ])->assertCreated();

    // The old row is still there, closed the day before the new one starts, so
    // a claim for a 2026 session still re-prices against the 2026 rate.
    $old = DB::table('benefit_programs')->where('id', $existing->id)->first();

    expect($old)->not->toBeNull()
        ->and($old->effective_to)->toBe('2027-01-01')
        ->and((float) $old->case_rate)->toBe((float) $existing->case_rate)
        ->and(DB::table('benefit_programs')->where('modality', 'hd')->count())->toBe(3);
});

it('refuses a benefit programme that does not start after the one in force', function () {
    $existing = currentHdProgram();

    $response = $this->actingAs(staffWithRole('admin'), 'sanctum')
        ->postJson('/api/v1/settings/benefit-programs', [
            'payer_id' => $existing->payer_id,
            'code' => 'PH_HD_BACKDATED',
            'name' => 'Backdated',
            'modality' => 'hd',
            'case_rate' => '9999.00',
            'sessions_per_period' => 200,
            'period_kind' => 'calendar_year',
            'currency' => 'PHP',
            'no_balance_billing' => true,
            'effective_from' => $existing->effective_from,
        ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('must start after it');
});

it('refuses a benefit period kind the ledger cannot price', function () {
    $existing = currentHdProgram();

    // rolling_year and lifetime both need an anchor date nobody has defined.
    // Refusing at the edge beats storing one and throwing at claim time.
    $this->actingAs(staffWithRole('admin'), 'sanctum')
        ->postJson('/api/v1/settings/benefit-programs', [
            'payer_id' => $existing->payer_id,
            'code' => 'OTHERPROG',
            'name' => 'Rolling',
            'modality' => 'hd',
            'case_rate' => '1000.00',
            'sessions_per_period' => 12,
            'period_kind' => 'rolling_year',
            'currency' => 'PHP',
            'no_balance_billing' => false,
            'effective_from' => '2027-01-01',
        ])
        ->assertStatus(422);
});

it('records unflagging a high-alert medication', function () {
    $admin = staffWithRole('admin');
    $drug = DB::table('medication_refs')->where('is_high_alert', 1)->first();

    expect($drug)->not->toBeNull();

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/settings/medications/{$drug->id}/high-alert", ['is_high_alert' => false])
        ->assertOk();

    // Invariant 7 reads this column: unflagged means no witness is required.
    expect((bool) DB::table('medication_refs')->where('id', $drug->id)->value('is_high_alert'))->toBeFalse();

    $entry = DB::table('audit_logs')
        ->where('auditable_type', 'table:medication_refs')
        ->orderByDesc('id')->first();

    expect((int) $entry->actor_id)->toBe((int) $admin->id)
        ->and(json_decode($entry->before_data, true)['is_high_alert'])->toBeTrue()
        ->and(json_decode($entry->after_data, true)['is_high_alert'])->toBeFalse();
});

it('stops the last administrator removing their own admin role', function () {
    $admin = staffWithRole('admin');

    $response = $this->actingAs($admin, 'sanctum')
        ->putJson("/api/v1/settings/staff/{$admin->public_id}/roles", ['roles' => ['nurse']]);

    // Otherwise the settings screen locks everyone out and the only way back in
    // is direct database access.
    $response->assertStatus(422);
    expect($response->json('message'))->toContain('only administrator')
        ->and(DB::table('role_staff')->where('staff_id', $admin->id)->where('role_code', 'admin')->exists())->toBeTrue();
});

it('lets an administrator step down once someone else can take over', function () {
    $admin = staffWithRole('admin');
    $successor = staffWithRole('admin');

    expect($successor->id)->not->toBe($admin->id);

    $this->actingAs($admin, 'sanctum')
        ->putJson("/api/v1/settings/staff/{$admin->public_id}/roles", ['roles' => ['nurse']])
        ->assertOk();

    expect(DB::table('role_staff')->where('staff_id', $admin->id)->pluck('role_code')->all())->toBe(['nurse']);
});

it('refuses a role that does not exist', function () {
    $admin = staffWithRole('admin');

    $this->actingAs($admin, 'sanctum')
        ->putJson("/api/v1/settings/staff/{$admin->public_id}/roles", ['roles' => ['admin', 'wizard']])
        ->assertStatus(422);
});

it('updates the facility and audits it', function () {
    $admin = staffWithRole('admin');

    $this->actingAs($admin, 'sanctum')
        ->patchJson('/api/v1/settings/facility', ['name' => 'Bacolod Renal Centre', 'timezone' => 'Asia/Manila'])
        ->assertOk()
        ->assertJsonPath('name', 'Bacolod Renal Centre');

    expect(DB::table('audit_logs')->where('auditable_type', 'table:facilities')->count())->toBe(1);
});

it('refuses a timezone that is not a timezone', function () {
    $this->actingAs(staffWithRole('admin'), 'sanctum')
        ->patchJson('/api/v1/settings/facility', ['timezone' => 'Middle Earth/Shire'])
        ->assertStatus(422);
});

it('refuses a benefit programme code that is already in use', function () {
    $existing = currentHdProgram();

    // `code` is globally unique. Without this check the caller gets a
    // duplicate-key 500 instead of a sentence naming the collision.
    $response = $this->actingAs(staffWithRole('admin'), 'sanctum')
        ->postJson('/api/v1/settings/benefit-programs', [
            'payer_id' => $existing->payer_id,
            'code' => $existing->code,
            'name' => 'Same code again',
            'modality' => 'hd',
            'case_rate' => '7000.00',
            'sessions_per_period' => 156,
            'period_kind' => 'calendar_year',
            'currency' => 'PHP',
            'no_balance_billing' => true,
            'effective_from' => '2028-01-01',
        ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('already used by another programme');
});

it('leaves no day without a programme in force', function () {
    $existing = currentHdProgram();

    $this->actingAs(staffWithRole('admin'), 'sanctum')->postJson('/api/v1/settings/benefit-programs', [
        'payer_id' => $existing->payer_id,
        'code' => 'PH_HD_2029',
        'name' => 'Next circular',
        'modality' => 'hd',
        'case_rate' => '7100.00',
        'sessions_per_period' => 156,
        'period_kind' => 'calendar_year',
        'currency' => 'PHP',
        'no_balance_billing' => true,
        'effective_from' => '2029-03-15',
    ])->assertCreated();

    // effective_to is exclusive in BenefitLedger::programFor(), so on the
    // changeover date exactly one programme must resolve -- not zero, and not
    // two. A one-day gap would price a claim against nothing.
    foreach (['2029-03-14', '2029-03-15', '2029-03-16'] as $date) {
        $inForce = DB::table('benefit_programs')
            ->where('modality', 'hd')
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $date))
            ->count();

        expect($inForce)->toBe(1, "expected exactly one hd programme in force on {$date}");
    }
});
