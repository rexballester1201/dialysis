<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Http;

use App\Domain\Clinical\Http\Requests\AdministerMedicationRequest;
use App\Domain\Clinical\Http\Requests\AmendSessionRequest;
use App\Domain\Clinical\Http\Requests\AppendEventRequest;
use App\Domain\Clinical\Http\Requests\AppendVitalRequest;
use App\Domain\Clinical\Http\Requests\CheckInSessionRequest;
use App\Domain\Clinical\Http\Requests\EndSessionRequest;
use App\Domain\Clinical\Http\Requests\StartSessionRequest;
use App\Domain\Clinical\Http\Resources\SessionEventResource;
use App\Domain\Clinical\Http\Resources\SessionVitalResource;
use App\Domain\Clinical\Http\Resources\TreatmentSessionResource;
use App\Domain\Clinical\Models\TreatmentSession;
use App\Domain\Clinical\Services\FlowSheetService;
use App\Domain\Clinical\Services\SessionLockService;
use App\Domain\Clinical\Services\SessionService;
use App\Domain\Core\Models\Staff;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The treatment record, from check-in to lock.
 *
 * Every write delegates: SessionService owns the state machine, FlowSheetService
 * owns what goes onto the flow sheet, SessionLockService owns signing and
 * amendment. This class validates, authorises and shapes the response.
 */
final class SessionController extends Controller
{
    public function __construct(
        private readonly SessionService $sessions,
        private readonly FlowSheetService $flowSheet,
        private readonly SessionLockService $lock,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TreatmentSession::class);

        $sessions = TreatmentSession::query()
            ->with('patient')
            ->when($request->filled('date'), fn ($query) => $query->where('session_date', $request->date('date')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->orderByDesc('session_date')
            ->orderBy('id')
            ->paginate((int) $request->integer('per_page', 25));

        return TreatmentSessionResource::collection($sessions);
    }

    public function show(TreatmentSession $session): TreatmentSessionResource
    {
        $this->authorize('view', $session);

        return new TreatmentSessionResource($session->load('patient'));
    }

    public function checkIn(CheckInSessionRequest $request, TreatmentSession $session): JsonResponse
    {
        $this->authorize('chart', $session);

        $updated = $this->sessions->checkIn($session, $request->validated(), $this->actor($request));

        return $this->withCohortWarnings(new TreatmentSessionResource($updated->load('patient')));
    }

    public function start(StartSessionRequest $request, TreatmentSession $session): JsonResponse
    {
        $this->authorize('chart', $session);

        $updated = $this->sessions->start($session, $request->validated(), $this->actor($request));

        return $this->withCohortWarnings(new TreatmentSessionResource($updated->load('patient')));
    }

    public function end(EndSessionRequest $request, TreatmentSession $session): TreatmentSessionResource
    {
        $this->authorize('chart', $session);

        return new TreatmentSessionResource(
            $this->sessions->end($session, $request->validated(), $this->actor($request))->load('patient')
        );
    }

    /** The flow sheet, in time order. */
    public function vitals(TreatmentSession $session): AnonymousResourceCollection
    {
        $this->authorize('view', $session);

        return SessionVitalResource::collection($session->vitals()->get());
    }

    public function appendVital(AppendVitalRequest $request, TreatmentSession $session): JsonResponse
    {
        $this->authorize('chart', $session);

        $vital = $this->flowSheet->appendVital($session, $request->validated(), $this->actor($request));

        return (new SessionVitalResource($vital))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function events(TreatmentSession $session): AnonymousResourceCollection
    {
        $this->authorize('view', $session);

        return SessionEventResource::collection($session->events()->get());
    }

    public function appendEvent(AppendEventRequest $request, TreatmentSession $session): JsonResponse
    {
        $this->authorize('chart', $session);

        $event = $this->flowSheet->appendEvent($session, $request->validated(), $this->actor($request));

        return (new SessionEventResource($event))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function administer(AdministerMedicationRequest $request, TreatmentSession $session): JsonResponse
    {
        $this->authorize('chart', $session);

        $administration = $this->flowSheet->administer($session, $request->validated(), $this->actor($request));

        return response()->json([
            'administered_at' => $administration->administered_at->toIso8601String(),
            'medication_id' => $administration->medication_id,
            'dose' => $administration->dose,
            'dose_unit' => $administration->dose_unit,
            'route' => $administration->route,
            'witnessed' => $administration->witnessed_by !== null,
            'not_given' => (bool) $administration->not_given,
        ], Response::HTTP_CREATED);
    }

    /** Nurse countersignature. The record locks once both signatures are in. */
    public function signNurse(Request $request, TreatmentSession $session): TreatmentSessionResource
    {
        $this->authorize('signAsNurse', $session);

        return new TreatmentSessionResource(
            $this->lock->signAsNurse($session, $this->actor($request))->load('patient')
        );
    }

    public function signPhysician(Request $request, TreatmentSession $session): TreatmentSessionResource
    {
        $this->authorize('signAsPhysician', $session);

        return new TreatmentSessionResource(
            $this->lock->signAsPhysician($session, $this->actor($request))->load('patient')
        );
    }

    /**
     * Correct a signed record.
     *
     * The original values are not overwritten in any sense that matters: the
     * before/after pair is in audit_logs and the reason is a session note.
     */
    public function amend(AmendSessionRequest $request, TreatmentSession $session): TreatmentSessionResource
    {
        $this->authorize('amend', $session);

        /** @var array<string, mixed> $changes */
        $changes = $request->validated()['changes'];

        return new TreatmentSessionResource(
            $this->lock->amend(
                $session,
                $changes,
                $request->string('reason')->toString(),
                $this->actor($request),
            )->load('patient')
        );
    }

    /**
     * Attach infection-control context to the response.
     *
     * `cohort_overrides` is empty on any normal assignment. Non-empty means the
     * caller deliberately proceeded past invariant 1 with a reason, which is
     * already on the chart -- it is echoed back so a screen can show what was
     * overridden rather than quietly succeeding.
     *
     * `machine_warnings` are hygiene notes that never block: how much has run on
     * the machine since it was last cleaned. The interval is unit policy.
     */
    private function withCohortWarnings(TreatmentSessionResource $resource): JsonResponse
    {
        $payload = $resource->response()->getData(true);
        $payload['cohort_overrides'] = $this->sessions->cohortOverrides;
        $payload['machine_warnings'] = $this->sessions->machineWarnings;

        return response()->json($payload);
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
