<?php

declare(strict_types=1);

use App\Domain\Core\Models\Patient;
use App\Domain\Core\Models\Staff;
use App\Domain\Core\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Bedside authentication
|--------------------------------------------------------------------------
| Sanctum personal access tokens, not SPA cookie mode: a tablet that has been
| offline for hours needs a bearer token it can reuse the instant the network
| returns.
|
| Every attempt lands in login_events. That table is the answer to "who tried to
| get in", and on a shared ward tablet it is the only answer there is.
*/

uses(TestCase::class, RefreshDatabase::class);

it('issues a device-bound token with the abilities the role carries', function () {
    $nurse = Staff::factory()->withRole('nurse')->create(['email' => 'nurse@centre.test']);

    $response = $this->postJson('/api/v1/auth/login', [
        'username' => 'nurse@centre.test',
        'password' => 'password',
        'device_id' => 'TABLET-01',
    ]);

    $response->assertOk()
        ->assertJsonPath('abilities', ['bedside'])
        ->assertJsonPath('device_id', 'TABLET-01')
        ->assertJsonPath('staff.public_id', $nurse->public_id);

    expect($response->json('token'))->toBeString()->not->toBeEmpty();

    $token = DB::table('personal_access_tokens')->latest('id')->first();

    expect($token->device_id)->toBe('TABLET-01')
        // 12 hours: one long shift plus handover.
        ->and(Carbon::parse($token->expires_at)->diffInHours(Carbon::now(), absolute: true))
        ->toBeGreaterThanOrEqual(11);
});

it('records every login attempt, successful or not', function () {
    Staff::factory()->withRole('nurse')->create(['email' => 'nurse@centre.test']);

    $this->postJson('/api/v1/auth/login', [
        'username' => 'nurse@centre.test', 'password' => 'password', 'device_id' => 'TABLET-01',
    ])->assertOk();

    $this->postJson('/api/v1/auth/login', [
        'username' => 'nurse@centre.test', 'password' => 'wrong', 'device_id' => 'TABLET-01',
    ])->assertStatus(422);

    expect(DB::table('login_events')->where('success', 1)->count())->toBe(1)
        ->and(DB::table('login_events')->where('success', 0)->value('failure_reason'))->toBe('bad_password');
});

it('locks the account after repeated failures', function () {
    $staff = Staff::factory()->withRole('nurse')->create(['email' => 'nurse@centre.test']);

    foreach (range(1, AuthService::MAX_FAILED_LOGINS) as $ignored) {
        $this->postJson('/api/v1/auth/login', [
            'username' => 'nurse@centre.test', 'password' => 'wrong', 'device_id' => 'TABLET-01',
        ])->assertStatus(422);
    }

    expect($staff->fresh()->locked_until)->not->toBeNull();

    // The lock is asserted against the service, not another HTTP call: the route
    // throttle is also 5/minute, so a sixth request would be refused as 429
    // before the lock could be reached. The two limits are deliberately
    // different defences -- the throttle is per IP and per minute, the lock
    // follows the account across both.
    expect(fn () => app(AuthService::class)->login('nurse@centre.test', 'password', 'TABLET-01'))
        ->toThrow(ValidationException::class);

    expect(DB::table('login_events')->where('failure_reason', 'account_locked')->count())->toBe(1)
        ->and(DB::table('personal_access_tokens')->count())->toBe(0);
});

it('re-issues a token from the clinical PIN on a device that has already signed in', function () {
    $nurse = Staff::factory()->withRole('nurse')->create(['email' => 'nurse@centre.test']);

    $this->postJson('/api/v1/auth/login', [
        'username' => 'nurse@centre.test', 'password' => 'password', 'device_id' => 'TABLET-01',
    ])->assertOk();

    $response = $this->postJson('/api/v1/auth/pin-unlock', [
        'staff_public_id' => $nurse->public_id,
        'pin' => '123456',
        'device_id' => 'TABLET-01',
    ]);

    $response->assertOk()->assertJsonPath('device_id', 'TABLET-01');

    // One live token per device: unlocking replaces, it does not accumulate.
    expect(DB::table('personal_access_tokens')->where('device_id', 'TABLET-01')->count())->toBe(1);
});

it('refuses a PIN from a device that has never completed a password login', function () {
    $nurse = Staff::factory()->withRole('nurse')->create();

    $this->postJson('/api/v1/auth/pin-unlock', [
        'staff_public_id' => $nurse->public_id,
        'pin' => '123456',
        'device_id' => 'STRANGE-TABLET',
    ])->assertStatus(422);

    expect(DB::table('login_events')->where('failure_reason', 'device_not_enrolled')->count())->toBe(1)
        ->and(DB::table('personal_access_tokens')->count())->toBe(0);
});

it('revokes a token presented from a different device', function () {
    $nurse = Staff::factory()->withRole('nurse')->create(['email' => 'nurse@centre.test']);
    $patient = Patient::factory()->create();

    $token = $this->postJson('/api/v1/auth/login', [
        'username' => 'nurse@centre.test', 'password' => 'password', 'device_id' => 'TABLET-01',
    ])->json('token');

    // Same token, different tablet. The credential has been copied, so it is
    // burned rather than merely refused.
    $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Device-Id' => 'TABLET-99'])
        ->getJson("/api/v1/patients/{$patient->public_id}")
        ->assertUnauthorized();

    expect(DB::table('personal_access_tokens')->count())->toBe(0)
        ->and(DB::table('login_events')->where('failure_reason', 'device_mismatch')->count())->toBe(1);

    // And it is dead everywhere, not just on the tablet that misused it.
    //
    // The guard has to be forgotten first: RequestGuard caches the user it
    // resolved, and the test harness reuses one application across requests, so
    // without this the second call would authenticate from memory against a row
    // that no longer exists. Every real request gets a fresh container.
    $this->app['auth']->forgetGuards();

    $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Device-Id' => 'TABLET-01'])
        ->getJson("/api/v1/patients/{$patient->public_id}")
        ->assertUnauthorized();
});

it('accepts the token on the device it was issued to', function () {
    $nurse = Staff::factory()->withRole('nurse')->create(['email' => 'nurse@centre.test']);
    $patient = Patient::factory()->create();

    $token = $this->postJson('/api/v1/auth/login', [
        'username' => 'nurse@centre.test', 'password' => 'password', 'device_id' => 'TABLET-01',
    ])->json('token');

    $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Device-Id' => 'TABLET-01'])
        ->getJson("/api/v1/patients/{$patient->public_id}")
        ->assertOk();
});

it('rate limits login attempts', function () {
    Staff::factory()->withRole('nurse')->create(['email' => 'nurse@centre.test']);

    // throttle:5,1 -- the sixth attempt inside the minute is refused outright.
    foreach (range(1, 5) as $ignored) {
        $this->postJson('/api/v1/auth/login', [
            'username' => 'nurse@centre.test', 'password' => 'wrong', 'device_id' => 'TABLET-01',
        ])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/login', [
        'username' => 'nurse@centre.test', 'password' => 'wrong', 'device_id' => 'TABLET-01',
    ])->assertStatus(429);
});

it('reports its own health', function () {
    $body = $this->getJson('/api/v1/health')->assertOk()->json();

    expect($body['status'])->toBe('ok')
        ->and($body['checks']['database']['status'])->toBe('ok')
        ->and($body['checks']['schema']['views'])->toBe(12)
        ->and($body['checks']['schema']['triggers'])->toBe(6)
        ->and($body['checks']['schema']['tables'])->toBeGreaterThanOrEqual(66)
        // Deferred, and it says so rather than reporting a pass it did not make.
        ->and($body['checks']['redis']['status'])->toBe('not_configured')
        ->and($body['checks']['backup']['status'])->toBe('not_configured');
});
