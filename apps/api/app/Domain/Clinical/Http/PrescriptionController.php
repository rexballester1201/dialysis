<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Http;

use App\Domain\Clinical\Http\Requests\StorePrescriptionRequest;
use App\Domain\Clinical\Models\HdPrescription;
use App\Domain\Clinical\Services\PrescriptionService;
use App\Domain\Core\Models\Patient;
use App\Domain\Core\Models\Staff;
use App\Domain\Core\Services\FacilityCalendar;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Prescriptions are versioned. Writing one closes the previous version in the
 * same transaction -- the hd_prescriptions triggers reject an overlap outright.
 */
final class PrescriptionController extends Controller
{
    public function __construct(
        private readonly PrescriptionService $prescriptions,
        private readonly FacilityCalendar $calendar,
    ) {}

    public function index(Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        return response()->json([
            'current' => DB::table('v_current_prescription')->where('patient_id', $patient->id)->first(),
            'versions' => HdPrescription::query()
                ->where('patient_id', $patient->id)
                ->orderByDesc('version')
                ->get(['version', 'modality', 'duration_min', 'sessions_per_week',
                    'blood_flow_ml_min', 'anticoagulant', 'effective_from', 'effective_to', 'change_reason']),
        ]);
    }

    public function store(StorePrescriptionRequest $request, Patient $patient): JsonResponse
    {
        // Conditional on record state as well as role: a closed chart is not
        // prescribed against.
        $this->authorize('prescribe', $patient);

        $attributes = $request->validated();
        unset($attributes['effective_from'], $attributes['reason']);

        $prescription = $this->prescriptions->revise(
            $patient,
            $attributes,
            $request->date('effective_from') ?? $this->calendar->today(),
            $this->actor($request),
            $request->string('reason')->toString(),
        );

        return response()->json([
            'version' => $prescription->version,
            'effective_from' => $prescription->effective_from->toDateString(),
            'duration_min' => $prescription->duration_min,
            'modality' => $prescription->modality,
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
