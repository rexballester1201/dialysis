<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Core\Models\Staff;
use App\Domain\Core\Services\AuthService;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * A token is bound to the tablet it was issued to.
 *
 * If it is presented from a different device_id the credential has been copied,
 * so it is revoked rather than merely refused -- refusing it leaves a working
 * token in the wrong hands. The attempt lands in `login_events`.
 *
 * Requests authenticated by `actingAs()` in tests carry a TransientToken, which
 * has no device binding to check, so they pass straight through.
 */
final class EnsureTokenDevice
{
    public function __construct(private readonly AuthService $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $staff = $request->user();
        $token = $staff instanceof Staff ? $staff->currentAccessToken() : null;

        if (! $staff instanceof Staff || ! $token instanceof PersonalAccessToken) {
            return $next($request);
        }

        $boundTo = $token->device_id;

        if ($boundTo === null) {
            return $next($request);
        }

        $presented = $request->header('X-Device-Id') ?? $request->input('device_id');

        if ($presented !== $boundTo) {
            $this->auth->revokeForDeviceMismatch($staff, $token, (string) $presented);

            return response()->json([
                'message' => 'This token was issued to a different device and has been revoked.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
