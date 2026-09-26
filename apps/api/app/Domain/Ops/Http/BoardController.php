<?php

declare(strict_types=1);

namespace App\Domain\Ops\Http;

use App\Domain\Clinical\Models\TreatmentSession;
use App\Domain\Clinical\Services\CohortGuard;
use App\Domain\Core\Models\Patient;
use App\Domain\Core\Models\Staff;
use App\Domain\Core\Services\FacilityCalendar;
use App\Domain\Ops\Http\Requests\GenerateBoardRequest;
use App\Domain\Ops\Services\SchedulingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The day's board: who is in which chair, in which shift.
 */
final class BoardController extends Controller
{
    public function __construct(
        private readonly SchedulingService $scheduling,
        private readonly CohortGuard $cohortGuard,
        private readonly FacilityCalendar $calendar,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TreatmentSession::class);

        $date = $request->date('date') ?? $this->calendar->today();
        $shiftCode = $request->string('shift')->toString() ?: null;

        return response()->json([
            'date' => $date->toDateString(),
            'sessions' => $this->scheduling->board($date, $shiftCode),
        ]);
    }

    /**
     * Materialise a day from the standing patterns.
     *
     * Idempotent, so the charge nurse can run it again after adding a patient
     * without doubling anyone up. Chair clashes are reported rather than
     * resolved: choosing the replacement chair is a human decision.
     */
    public function generate(GenerateBoardRequest $request): JsonResponse
    {
        $this->authorize('create', TreatmentSession::class);

        $date = $request->date('date') ?? $this->calendar->today();

        $outcome = $this->scheduling->generateDay($date, $this->actor($request));

        return response()->json([
            'date' => $date->toDateString(),
            'created' => $outcome['created'],
            'skipped' => $outcome['skipped'],
            'clashes' => $outcome['clashes'],
        ]);
    }

    /**
     * Chairs this patient may occupy in a given slot.
     *
     * Filtered by infection-control cohort, so an HBV patient is never offered a
     * clean chair to begin with. The detective control still exists because this
     * is only the happy path -- a booking made any other way bypasses it.
     */
    public function availableStations(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $date = $request->date('date') ?? $this->calendar->today();
        $shiftId = $request->integer('shift_id');

        return response()->json([
            'date' => $date->toDateString(),
            'cohort' => $patient->cohort()->value,
            'stations' => $this->cohortGuard->availableStations($patient, $date->toDateString(), $shiftId),
        ]);
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
