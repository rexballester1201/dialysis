<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Http;

use App\Domain\Clinical\Http\Requests\StoreSerologyRequest;
use App\Domain\Clinical\Http\Resources\SerologyResultResource;
use App\Domain\Clinical\Models\SerologyResult;
use App\Domain\Clinical\Services\SerologyService;
use App\Domain\Core\Models\Patient;
use App\Domain\Core\Models\Staff;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class SerologyController extends Controller
{
    public function __construct(private readonly SerologyService $serology) {}

    public function index(Patient $patient): AnonymousResourceCollection
    {
        $this->authorize('view', $patient);

        $results = SerologyResult::query()
            ->where('patient_id', $patient->id)
            ->orderByDesc('specimen_date')
            ->orderByDesc('id')
            ->get();

        return SerologyResultResource::collection($results);
    }

    /**
     * File a result.
     *
     * Responds 201 with the cohort before and after. When the cohort moved, the
     * response also carries the sessions that are now booked into a chair or
     * machine the new cohort does not permit -- the caller is expected to show
     * that to a human, not swallow it.
     */
    public function store(StoreSerologyRequest $request, Patient $patient): JsonResponse
    {
        $this->authorize('update', $patient);

        $outcome = $this->serology->record($patient, $request->validated(), $this->actor($request));

        return response()->json([
            'result' => new SerologyResultResource($outcome['result']),
            'cohort_before' => $outcome['cohort_before']->value,
            'cohort_after' => $outcome['cohort_after']->value,
            'cohort_changed' => $outcome['cohort_before'] !== $outcome['cohort_after'],
            'affected_sessions' => $outcome['affected_sessions'],
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
