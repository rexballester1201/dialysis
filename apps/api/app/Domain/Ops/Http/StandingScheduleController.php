<?php

declare(strict_types=1);

namespace App\Domain\Ops\Http;

use App\Domain\Clinical\Models\TreatmentSession;
use App\Domain\Core\Models\Patient;
use App\Domain\Core\Models\Staff;
use App\Domain\Core\Services\FacilityCalendar;
use App\Domain\Ops\Http\Requests\SetStandingScheduleRequest;
use App\Domain\Ops\Services\SchedulingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * A patient's standing pattern -- the thing that makes them turn up on the board
 * three times a week without anyone re-entering it.
 */
final class StandingScheduleController extends Controller
{
    public function __construct(
        private readonly SchedulingService $scheduling,
        private readonly FacilityCalendar $calendar,
    ) {}

    public function index(Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $history = DB::table('standing_schedules as ss')
            ->leftJoin('shifts as sh', 'sh.id', '=', 'ss.shift_id')
            ->leftJoin('stations as st', 'st.id', '=', 'ss.station_id')
            ->where('ss.patient_id', $patient->id)
            ->orderByDesc('ss.effective_from')
            ->get([
                'ss.weekday_mask', 'ss.effective_from', 'ss.effective_to', 'ss.notes',
                'sh.code as shift_code', 'st.code as station_code',
            ]);

        $current = $this->scheduling->standingScheduleOn($patient, now());

        return response()->json([
            'current' => $current,
            'history' => $history,
        ]);
    }

    public function store(SetStandingScheduleRequest $request, Patient $patient): JsonResponse
    {
        // Scheduling the floor, not editing the chart: the session policy governs.
        $this->authorize('create', TreatmentSession::class);

        $schedule = $this->scheduling->setStandingSchedule(
            $patient,
            $request->validated(),
            $request->date('effective_from') ?? $this->calendar->today(),
            $this->actor($request),
        );

        return response()->json(['schedule' => $schedule], Response::HTTP_CREATED);
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
