<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Exceptions;

use App\Support\Exceptions\DomainRuleException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The observation is already on the flow sheet.
 *
 * Vitals are keyed by (session_id, recorded_at) and carry a client_uuid, so a
 * retry -- a tablet resending its outbox, a nurse tapping twice -- lands on a
 * unique index rather than creating a second reading. 409, because the server
 * already has it: this is not a failure the caller needs to recover from.
 */
final class DuplicateObservationException extends DomainRuleException
{
    protected int $status = Response::HTTP_CONFLICT;
}
