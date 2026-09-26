<?php

declare(strict_types=1);

namespace App\Domain\Core\Http;

use App\Domain\Core\Http\Requests\LoginRequest;
use App\Domain\Core\Http\Requests\PinUnlockRequest;
use App\Domain\Core\Http\Resources\TokenResource;
use App\Domain\Core\Models\Staff;
use App\Domain\Core\Services\AuthService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Validate (FormRequest) -> delegate to a service -> return a Resource.
 * No credential logic lives here.
 */
final class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function login(LoginRequest $request): TokenResource
    {
        $issued = $this->auth->login(
            $request->string('username')->toString(),
            $request->string('password')->toString(),
            $request->string('device_id')->toString(),
        );

        return new TokenResource($issued->token, $issued->staff);
    }

    public function pinUnlock(PinUnlockRequest $request): TokenResource
    {
        $issued = $this->auth->pinUnlock(
            $request->string('staff_public_id')->toString(),
            $request->string('pin')->toString(),
            $request->string('device_id')->toString(),
        );

        return new TokenResource($issued->token, $issued->staff);
    }

    /** Revoke only the token that made this request, never the nurse's other devices. */
    public function logout(Request $request): JsonResponse
    {
        $staff = $request->user();

        $token = $staff instanceof Staff ? $staff->currentAccessToken() : null;

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['revoked' => true]);
    }
}
