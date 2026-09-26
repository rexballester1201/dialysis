<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Exceptions;

use App\Domain\Clinical\Models\TreatmentSession;
use App\Support\Exceptions\DomainRuleException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A write reached a session that has already been signed and locked.
 *
 * 409 rather than 422: the request was well formed, the caller's view of the
 * record is simply out of date. Over sync this becomes a `conflict` verdict for
 * that one operation, and the rest of the batch still applies.
 */
final class SessionLockedException extends DomainRuleException
{
    protected int $status = Response::HTTP_CONFLICT;

    public function __construct(public readonly TreatmentSession $session)
    {
        parent::__construct(
            "Session {$session->public_id} was signed and locked at "
            .($session->locked_at?->toIso8601String() ?? 'an unknown time')
            .'. Corrections go through the amendment path.'
        );
    }
}
