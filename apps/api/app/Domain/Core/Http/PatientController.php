<?php

declare(strict_types=1);

namespace App\Domain\Core\Http;

use App\Domain\Core\Http\Requests\ChangePatientStatusRequest;
use App\Domain\Core\Http\Requests\StorePatientRequest;
use App\Domain\Core\Http\Requests\UpdatePatientRequest;
use App\Domain\Core\Http\Resources\PatientResource;
use App\Domain\Core\Models\Patient;
use App\Domain\Core\Models\Staff;
use App\Domain\Core\Services\FacilityCalendar;
use App\Domain\Core\Services\PatientService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The patient registry. Thin by construction: validate, authorise, delegate,
 * return a Resource.
 */
final class PatientController extends Controller
{
    public function __construct(
        private readonly PatientService $patients,
        private readonly FacilityCalendar $calendar,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Patient::class);

        $patients = Patient::query()
            ->when(
                $request->string('search')->trim()->toString() !== '',
                function ($query) use ($request): void {
                    $term = $request->string('search')->trim()->toString();

                    $query->where(function ($inner) use ($term): void {
                        $inner->where('mrn', 'like', "{$term}%")
                            ->orWhere('last_name', 'like', "{$term}%")
                            ->orWhere('first_name', 'like', "{$term}%");
                    });
                },
            )
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')->toString()),
            )
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate((int) $request->integer('per_page', 25));

        return PatientResource::collection($patients);
    }

    public function store(StorePatientRequest $request): PatientResource
    {
        $this->authorize('create', Patient::class);

        $patient = $this->patients->register($request->validated(), $this->actor($request));

        return new PatientResource($patient);
    }

    public function show(Patient $patient): PatientResource
    {
        $this->authorize('view', $patient);

        return new PatientResource($patient);
    }

    public function update(UpdatePatientRequest $request, Patient $patient): PatientResource
    {
        $this->authorize('update', $patient);

        $patient = $this->patients->updateDetails($patient, $request->validated(), $this->actor($request));

        return new PatientResource($patient);
    }

    /**
     * Soft delete only.
     *
     * A patient record is never hard-deleted: sessions, claims and the audit
     * trail all reference it, and a chart that vanishes cannot be audited.
     */
    public function destroy(Request $request, Patient $patient): Response
    {
        $this->authorize('delete', $patient);

        $patient->delete();

        return response()->noContent();
    }

    /** Status changes go through their own endpoint so the history is never optional. */
    public function changeStatus(ChangePatientStatusRequest $request, Patient $patient): PatientResource
    {
        $this->authorize('update', $patient);

        $patient = $this->patients->changeStatus(
            $patient,
            $request->string('status')->toString(),
            $request->date('effective_on') ?? $this->calendar->today(),
            $request->string('reason')->toString() ?: null,
            $request->string('destination')->toString() ?: null,
            $this->actor($request),
        );

        return new PatientResource($patient);
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
