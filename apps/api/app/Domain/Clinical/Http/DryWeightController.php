<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Http;

use App\Domain\Core\Http\Requests\SetDryWeightRequest;
use App\Domain\Core\Models\Patient;
use App\Domain\Core\Models\Staff;
use App\Domain\Core\Services\FacilityCalendar;
use App\Domain\Core\Services\PatientService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Dry weight history.
 *
 * Effective-dated rows, never a mutable column: IDWG and the UF goal for a past
 * session are only interpretable against the target that was in force that day.
 */
final class DryWeightController extends Controller
{
    public function __construct(
        private readonly PatientService $patients,
        private readonly FacilityCalendar $calendar,
    ) {}

    public function index(Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $history = DB::table('dry_weights')
            ->where('patient_id', $patient->id)
            ->orderByDesc('effective_from')
            ->get(['weight_kg', 'effective_from', 'reason']);

        return response()->json([
            'current_kg' => $this->patients->currentDryWeight($patient),
            'history' => $history,
        ]);
    }

    public function store(SetDryWeightRequest $request, Patient $patient): JsonResponse
    {
        $this->authorize('update', $patient);

        $this->patients->setDryWeight(
            $patient,
            $request->string('weight_kg')->toString(),
            $request->date('effective_from') ?? $this->calendar->today(),
            $request->string('reason')->toString() ?: null,
            $this->actor($request),
        );

        return response()->json([
            'current_kg' => $this->patients->currentDryWeight($patient),
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
