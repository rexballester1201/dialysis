<?php

declare(strict_types=1);

namespace App\Domain\Core\Services;

use App\Domain\Core\Models\Staff;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Issuing, re-issuing and revoking bedside credentials.
 *
 * Sanctum personal access tokens, not SPA cookie mode: a tablet that has been
 * offline for hours needs a bearer token it can reuse the instant the network
 * returns, with no session cookie and no CSRF round trip.
 *
 * Every outcome -- success or failure -- lands in `login_events`. That table is
 * the record of who tried to get in, which is why failures are written before
 * the exception is thrown rather than after.
 */
final class AuthService
{
    /** A token lives one long shift plus handover. */
    public const TOKEN_TTL_HOURS = 12;

    /** Consecutive failures before the account stops accepting passwords. */
    public const MAX_FAILED_LOGINS = 5;

    public const LOCKOUT_MINUTES = 15;

    /**
     * Role to token ability. A staff member gets the union of their roles'
     * abilities, so a head nurse who also does billing carries both.
     *
     * @var array<string, list<string>>
     */
    private const ROLE_ABILITIES = [
        'admin' => ['admin', 'billing', 'bedside'],
        'billing' => ['billing'],
        'nephrologist' => ['bedside'],
        'head_nurse' => ['bedside'],
        'nurse' => ['bedside'],
        'technician' => ['bedside'],
        'records' => [],
        'dietitian' => [],
        'readonly' => [],
    ];

    /**
     * Exchange a password for a device-bound token.
     *
     * @throws ValidationException on any failure, deliberately without saying
     *                             which of username or password was wrong.
     */
    public function login(string $username, string $password, string $deviceId): IssuedToken
    {
        $staff = Staff::query()
            ->where(fn ($query) => $query->where('email', $username)->orWhere('employee_no', $username))
            ->first();

        if (! $staff instanceof Staff) {
            $this->record(null, $username, false, 'unknown_user');

            throw ValidationException::withMessages(['username' => 'These credentials do not match our records.']);
        }

        if (! $staff->is_active) {
            $this->record($staff, $username, false, 'inactive_account');

            throw ValidationException::withMessages(['username' => 'This account is not active.']);
        }

        if ($this->isLockedOut($staff)) {
            $this->record($staff, $username, false, 'account_locked');

            throw ValidationException::withMessages(['username' => 'This account is temporarily locked.']);
        }

        if ($staff->password === null || ! Hash::check($password, $staff->password)) {
            $this->registerFailure($staff);
            $this->record($staff, $username, false, 'bad_password');

            throw ValidationException::withMessages(['username' => 'These credentials do not match our records.']);
        }

        $this->record($staff, $username, true);

        return $this->issue($staff, $deviceId);
    }

    /**
     * Re-issue a token from the 6-digit clinical PIN after the idle lock.
     *
     * Nurses re-authenticate roughly twenty times a shift. Demanding a full
     * password that often guarantees one shared logged-in account, which
     * destroys the audit trail -- the PIN is the deliberate trade.
     *
     * A PIN is not a password, so it is fenced in: the tablet must already have
     * completed a password login for this staff member (proved by an existing
     * token bound to this device_id), the route is throttled, and every attempt
     * is recorded.
     */
    public function pinUnlock(string $staffPublicId, string $pin, string $deviceId): IssuedToken
    {
        $staff = Staff::query()->where('public_id', $staffPublicId)->first();

        if (! $staff instanceof Staff || ! $staff->is_active) {
            $this->record($staff, $staffPublicId, false, 'pin_unknown_user');

            throw ValidationException::withMessages(['pin' => 'This PIN is not valid on this device.']);
        }

        if ($this->isLockedOut($staff)) {
            $this->record($staff, $staffPublicId, false, 'account_locked');

            throw ValidationException::withMessages(['pin' => 'This account is temporarily locked.']);
        }

        if (! $this->deviceIsEnrolled($staff, $deviceId)) {
            $this->record($staff, $staffPublicId, false, 'device_not_enrolled');

            throw ValidationException::withMessages([
                'pin' => 'This device has not been signed in with a password for this user.',
            ]);
        }

        if ($staff->clinical_pin_hash === null || ! Hash::check($pin, $staff->clinical_pin_hash)) {
            $this->registerFailure($staff);
            $this->record($staff, $staffPublicId, false, 'bad_pin');

            throw ValidationException::withMessages(['pin' => 'This PIN is not valid on this device.']);
        }

        $this->record($staff, $staffPublicId, true);

        return $this->issue($staff, $deviceId);
    }

    /**
     * Burn a token presented from a device it was not issued to.
     *
     * A bearer token that turns up on a second tablet is either a copied
     * credential or a cloned device image. Either way the token is revoked, not
     * merely refused, and the attempt is recorded.
     */
    public function revokeForDeviceMismatch(Staff $staff, PersonalAccessToken $token, string $presentedDeviceId): void
    {
        $token->delete();

        $this->record($staff, $staff->email, false, 'device_mismatch');
    }

    /**
     * The abilities this staff member's roles entitle them to.
     *
     * @return list<string>
     */
    public function abilitiesFor(Staff $staff): array
    {
        $abilities = DB::table('role_staff')
            ->where('staff_id', $staff->id)
            ->pluck('role_code')
            ->flatMap(fn (string $role): array => self::ROLE_ABILITIES[$role] ?? [])
            ->unique()
            ->all();

        return array_values($abilities);
    }

    private function issue(Staff $staff, string $deviceId): IssuedToken
    {
        $abilities = $this->abilitiesFor($staff);

        // One live token per device. Re-authenticating on a tablet replaces the
        // credential there and leaves this nurse's other devices alone.
        $staff->tokens()->where('device_id', $deviceId)->delete();

        $token = $staff->createToken(
            name: $deviceId,
            abilities: $abilities === [] ? ['none'] : $abilities,
            expiresAt: Carbon::now()->addHours(self::TOKEN_TTL_HOURS),
        );

        $token->accessToken->forceFill(['device_id' => $deviceId])->save();

        $staff->forceFill([
            'last_login_at' => Carbon::now(),
            'failed_logins' => 0,
            'locked_until' => null,
        ])->save();

        return new IssuedToken($token, $staff);
    }

    private function deviceIsEnrolled(Staff $staff, string $deviceId): bool
    {
        // Expired tokens still count. A shift can outlast the 12-hour TTL and
        // the nurse is still standing at the chair when it does.
        return $staff->tokens()->where('device_id', $deviceId)->exists();
    }

    private function isLockedOut(Staff $staff): bool
    {
        return $staff->locked_until !== null && $staff->locked_until->isFuture();
    }

    private function registerFailure(Staff $staff): void
    {
        $failures = (int) $staff->failed_logins + 1;

        $staff->forceFill([
            'failed_logins' => $failures,
            'locked_until' => $failures >= self::MAX_FAILED_LOGINS
                ? Carbon::now()->addMinutes(self::LOCKOUT_MINUTES)
                : $staff->locked_until,
        ])->save();
    }

    private function record(?Staff $staff, ?string $username, bool $success, ?string $reason = null): void
    {
        DB::table('login_events')->insert([
            'occurred_at' => Carbon::now(),
            'staff_id' => $staff?->id,
            'username' => $username === null ? null : mb_substr($username, 0, 160),
            'success' => $success,
            'failure_reason' => $reason,
            'ip_address' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 255),
        ]);
    }
}
