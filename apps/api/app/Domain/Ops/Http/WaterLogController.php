<?php

declare(strict_types=1);

namespace App\Domain\Ops\Http;

use App\Domain\Core\Models\Staff;
use App\Domain\Core\Services\FacilityCalendar;
use App\Domain\Ops\Http\Requests\StoreWaterLogRequest;
use App\Domain\Ops\Services\WaterComplianceService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Water compliance: the check that decides whether the unit dialyses today.
 *
 * "Today" is the unit's calendar day, not the server's -- see FacilityCalendar.
 */
final class WaterLogController extends Controller
{
    public function __construct(
        private readonly WaterComplianceService $water,
        private readonly FacilityCalendar $calendar,
    ) {}

    /**
     * One of the unit's days: whether it is cleared, and the checks behind that.
     *
     * Carries what the Water screen needs to record the next check too -- the
     * systems, the shifts, and whether the person asking may record one -- so
     * the screen renders from a single answer.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('water.view');

        $date = $request->date('date') ?? $this->calendar->today();
        $actor = $request->user();

        return response()->json([
            'date' => $date->toDateString(),
            'today' => $this->calendar->todayString(),
            // Which clock the day is counted on; a screen shows times in it.
            'timezone' => $this->calendar->timezone(),
            'action_limit_ppm' => WaterComplianceService::TOTAL_CHLORINE_LIMIT_PPM,
            'clearance' => $this->water->clearanceFor($date),
            // What the start gate would actually do, which is not always what the
            // latest reading suggests -- see startGate().
            'gate' => $this->water->startGate($date),
            'logs' => $this->water->logsFor($date),
            'systems' => $this->water->activeSystems(),
            'shifts' => $this->water->shifts(),
            'can_record' => $actor instanceof Staff && Gate::forUser($actor)->allows('water.record'),
        ]);
    }

    /**
     * Log a check.
     *
     * Responds 201 with the resulting clearance, so the caller learns in one
     * round trip whether the unit may start treating -- a breach is not an
     * error to be retried, it is an answer.
     */
    public function store(StoreWaterLogRequest $request): JsonResponse
    {
        // The renal technician's job, per the seeded role description.
        $this->authorize('water.record');

        $log = $this->water->recordDailyLog($request->validated(), $this->actor($request));

        // The day this check counts for is the unit's day it was taken on. The
        // UTC date of a 05:30 Manila check is yesterday.
        $day = $this->calendar->dateOf($log->logged_at);

        return response()->json([
            'logged_at' => $log->logged_at->toIso8601String(),
            'date' => $day->toDateString(),
            'total_chlorine_ppm' => $log->total_chlorine_ppm,
            'is_out_of_range' => $log->is_out_of_range,
            'action_limit_ppm' => WaterComplianceService::TOTAL_CHLORINE_LIMIT_PPM,
            'clearance' => $this->water->clearanceFor($day),
            'gate' => $this->water->startGate($day),
        ], Response::HTTP_CREATED);
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
