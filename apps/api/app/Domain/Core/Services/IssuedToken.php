<?php

declare(strict_types=1);

namespace App\Domain\Core\Services;

use App\Domain\Core\Models\Staff;
use Laravel\Sanctum\NewAccessToken;

/**
 * A freshly minted token and the staff member it belongs to.
 *
 * Sanctum exposes the owner as `$token->accessToken->tokenable`, which is typed
 * as a bare Model. Carrying the Staff alongside keeps the caller from having to
 * narrow it back down on every use.
 */
final readonly class IssuedToken
{
    public function __construct(
        public NewAccessToken $token,
        public Staff $staff,
    ) {}
}
