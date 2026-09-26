<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Exceptions;

use App\Support\Exceptions\DomainRuleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The assignment would breach infection-control segregation.
 *
 * Refused, not warned. This is the highest-consequence rule in the unit, and a
 * warning in a response body is only as good as the screen that renders it.
 *
 * There is an override, because a unit with one broken HBV chair and a patient
 * who needs dialysing today has to be able to proceed -- but it is explicit, it
 * carries a reason, and the reason goes on the chart. A rule that cannot be
 * overridden gets worked around outside the system, where nothing records it.
 */
final class CohortViolationException extends DomainRuleException
{
    protected int $status = Response::HTTP_CONFLICT;

    /**
     * @param  list<string>  $violations
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct(implode(' ', $violations));
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'violations' => $this->violations,
            // Naming the way through, so a client does not have to guess it.
            'override_with' => 'cohort_override_reason',
            'override_note' => 'Proceeding anyway requires a reason. It is recorded on the chart and in the audit trail.',
        ], $this->status);
    }
}
