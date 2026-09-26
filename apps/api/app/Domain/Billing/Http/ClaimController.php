<?php

declare(strict_types=1);

namespace App\Domain\Billing\Http;

use App\Domain\Billing\Http\Requests\GenerateClaimRequest;
use App\Domain\Billing\Http\Requests\RecordRemittanceRequest;
use App\Domain\Billing\Http\Requests\TransitionClaimRequest;
use App\Domain\Billing\Http\Resources\ClaimSummaryResource;
use App\Domain\Billing\Models\Claim;
use App\Domain\Billing\Services\BenefitLedger;
use App\Domain\Core\Models\Patient;
use App\Domain\Core\Models\Staff;
use App\Domain\Core\Services\FacilityCalendar;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Claims against a payer benefit.
 *
 * The ledger owns the rules; this shapes the request and the response. Every
 * write answers with the same detail the claim screen reads, so a screen never
 * shows a status the server has already moved on from.
 */
final class ClaimController extends Controller
{
    public function __construct(
        private readonly BenefitLedger $ledger,
        private readonly FacilityCalendar $calendar,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Claim::class);

        return ClaimSummaryResource::collection($this->ledger->search(
            $request->string('status')->toString() ?: null,
            $request->string('q')->trim()->toString() ?: null,
            (int) $request->integer('per_page', 25),
        ))->response();
    }

    /**
     * What could be claimed for a patient, grouped by program and period, with
     * what is left of each allotment.
     *
     * The allotment and the rate both come off the effective-dated program row;
     * neither is a constant anywhere in this codebase.
     */
    public function claimable(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('create', Claim::class);

        return response()->json($this->ledger->claimable(
            $patient,
            $request->date('from'),
            $request->date('to') ?? $this->calendar->today(),
        ));
    }

    public function generate(GenerateClaimRequest $request, Patient $patient): JsonResponse
    {
        $this->authorize('create', Claim::class);

        /** @var list<string> $ids */
        $ids = array_values(array_map('strval', $request->array('session_public_ids')));

        $claim = $this->ledger->generateClaim($patient, $ids, $this->actor($request));

        return response()->json($this->ledger->detail($claim), Response::HTTP_CREATED);
    }

    public function show(Claim $claim): JsonResponse
    {
        $this->authorize('view', $claim);

        return response()->json($this->ledger->detail($claim));
    }

    public function transition(TransitionClaimRequest $request, Claim $claim): JsonResponse
    {
        $this->authorize('transition', $claim);

        $updated = $this->ledger->transition(
            $claim,
            $request->string('status')->toString(),
            $this->actor($request),
            $request->string('remarks')->trim()->toString() ?: null,
        );

        return response()->json($this->ledger->detail($updated));
    }

    /**
     * Record the payer's remittance advice.
     *
     * The status follows the money: a claim cannot be marked paid while the
     * figures say it was denied or short-paid.
     */
    public function remit(RecordRemittanceRequest $request, Claim $claim): JsonResponse
    {
        $this->authorize('transition', $claim);

        $updated = $this->ledger->recordRemittance($claim, $request->validated(), $this->actor($request));

        return response()->json($this->ledger->detail($updated));
    }

    /** What the unit is still owed, and what it lost to denials. */
    public function outstanding(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Claim::class);

        return response()->json($this->ledger->outstanding(
            $request->string('from')->toString() ?: null,
            $request->string('to')->toString() ?: null,
        ));
    }

    private function actor(Request $request): Staff
    {
        $actor = $request->user();

        if (! $actor instanceof Staff) {
            abort(401);
        }

        return $actor;
    }
}
