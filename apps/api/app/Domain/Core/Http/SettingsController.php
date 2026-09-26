<?php

declare(strict_types=1);

namespace App\Domain\Core\Http;

use App\Domain\Core\Models\Staff;
use App\Domain\Core\Services\SettingsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Unit configuration.
 *
 * Administrators only, enforced on every action rather than once at the group
 * level -- a route added later without the check would otherwise inherit
 * nothing and fail open, which is the same failure mode CLAUDE.md records for
 * undiscovered policies.
 */
final class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    public function index(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        return response()->json($this->settings->all());
    }

    public function updateFacility(Request $request): JsonResponse
    {
        $actor = $this->assertAdmin($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'licence_no' => ['sometimes', 'nullable', 'string', 'max:60'],
            'accreditation_no' => ['sometimes', 'nullable', 'string', 'max:60'],
            // Not display only: FacilityCalendar counts the unit's day in this
            // zone -- which day a water check clears, the board's today, stock
            // expiry. Everything is still stored UTC.
            'timezone' => ['sometimes', 'string', 'max:64', 'timezone'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'country_code' => ['sometimes', 'string', 'size:2'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'email', 'max:160'],
            'station_count' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        return response()->json($this->settings->updateFacility($validated, $actor));
    }

    public function setStationCohorts(Request $request, int $station): JsonResponse
    {
        $actor = $this->assertAdmin($request);

        $validated = $request->validate([
            // `present`, not `required`: an empty list is a meaningful value
            // here (it makes the chair unrestricted) and Laravel treats [] as
            // empty, so `required` would refuse the one case that most needs
            // recording.
            'cohorts' => ['present', 'array'],
            'cohorts.*' => ['string', 'in:clean,hbv,hcv'],
        ]);

        return response()->json(['stations' => $this->settings->setStationCohorts($station, $validated['cohorts'], $actor)]);
    }

    public function addBenefitProgram(Request $request): JsonResponse
    {
        $actor = $this->assertAdmin($request);

        $validated = $request->validate([
            'payer_id' => ['required', 'integer', 'exists:payers,id'],
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:160'],
            'modality' => ['required', 'string', 'max:12'],
            'case_rate' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'sessions_per_period' => ['required', 'integer', 'min:1'],
            // rolling_year and lifetime both need an anchor date nobody has
            // defined, and the ledger throws on them. Refused at the edge so
            // the refusal names the reason.
            'period_kind' => ['required', 'string', 'in:calendar_year,month'],
            'currency' => ['required', 'string', 'size:3'],
            'no_balance_billing' => ['required', 'boolean'],
            'effective_from' => ['required', 'date'],
            'circular_ref' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['benefit_programs' => $this->settings->addBenefitProgram($validated, $actor)], 201);
    }

    public function setHighAlert(Request $request, int $medication): JsonResponse
    {
        $actor = $this->assertAdmin($request);

        $validated = $request->validate(['is_high_alert' => ['required', 'boolean']]);

        return response()->json(['high_alert' => $this->settings->setHighAlert($medication, $validated['is_high_alert'], $actor)]);
    }

    public function setStaffRoles(Request $request, string $staff): JsonResponse
    {
        $actor = $this->assertAdmin($request);

        $validated = $request->validate([
            'roles' => ['present', 'array'],
            'roles.*' => ['string', 'max:40'],
        ]);

        return response()->json(['staff' => $this->settings->setStaffRoles($staff, $validated['roles'], $actor)]);
    }

    private function assertAdmin(Request $request): Staff
    {
        $actor = $request->user();

        if (! $actor instanceof Staff) {
            abort(401);
        }

        abort_unless($actor->hasRole('admin'), 403, 'Only an administrator can change unit settings.');

        return $actor;
    }
}
