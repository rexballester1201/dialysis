<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A clinical or business rule refused the request.
 *
 * Refusing to sign an incomplete record, or to re-activate a transferred
 * patient without a reason, is the system working -- not the system breaking.
 * These render as 422 rather than 500 so a client can show the nurse what is
 * wrong, and so a genuine 500 still means what it should.
 *
 * Extends RuntimeException deliberately: the services already threw
 * RuntimeException and the invariant tests assert against it.
 */
class DomainRuleException extends RuntimeException
{
    protected int $status = Response::HTTP_UNPROCESSABLE_ENTITY;

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
        ], $this->status);
    }

    public function status(): int
    {
        return $this->status;
    }
}
