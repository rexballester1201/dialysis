<?php

declare(strict_types=1);

namespace App\Domain\Ops\Http;

use App\Domain\Core\Models\Staff;
use App\Domain\Ops\Http\Requests\RecordDisinfectionRequest;
use App\Domain\Ops\Http\Requests\RecordMaintenanceRequest;
use App\Domain\Ops\Services\MachineService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * The machine register and its service history.
 */
final class MachineController extends Controller
{
    public function __construct(private readonly MachineService $machines) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('water.view');

        $machines = DB::table('machines as m')
            ->leftJoin('stations as st', 'st.id', '=', 'm.home_station_id')
            ->when($request->filled('status'), fn ($q) => $q->where('m.status', $request->string('status')->toString()))
            ->orderBy('m.asset_tag')
            ->get([
                'm.id', 'm.asset_tag', 'm.manufacturer', 'm.model', 'm.status',
                'm.dedicated_cohort', 'm.total_run_hours', 'm.supports_online_clearance',
                'st.code as home_station',
            ]);

        return response()->json([
            'machines' => $machines,
            // Overdue first: this is the list somebody has to work through.
            'maintenance_due' => $this->machines->maintenanceDue(),
        ]);
    }

    public function show(int $machine): JsonResponse
    {
        $this->authorize('water.view');

        $found = $this->machines->find($machine);

        return response()->json([
            'asset_tag' => $found->asset_tag,
            'status' => $found->status,
            'dedicated_cohort' => $found->dedicated_cohort,
            'unavailable_reason' => $this->machines->unavailableReason($found),
            'disinfection' => DB::table('machine_disinfection_logs')
                ->where('machine_id', $found->id)
                ->orderByDesc('performed_at')
                ->limit(20)
                ->get(['performed_at', 'method', 'agent', 'duration_min', 'residual_test_result']),
            'maintenance' => DB::table('machine_maintenance_logs')
                ->where('machine_id', $found->id)
                ->orderByDesc('performed_on')
                ->limit(20)
                ->get(['performed_on', 'maintenance_type', 'description', 'passed', 'next_due_on']),
        ]);
    }

    public function disinfect(RecordDisinfectionRequest $request, int $machine): JsonResponse
    {
        $this->authorize('water.record');

        $updated = $this->machines->recordDisinfection($machine, $request->validated(), $this->actor($request));

        return response()->json([
            'asset_tag' => $updated->asset_tag,
            'status' => $updated->status,
        ], Response::HTTP_CREATED);
    }

    public function maintain(RecordMaintenanceRequest $request, int $machine): JsonResponse
    {
        $this->authorize('water.record');

        $updated = $this->machines->recordMaintenance($machine, $request->validated(), $this->actor($request));

        return response()->json([
            'asset_tag' => $updated->asset_tag,
            // A failed safety test takes the machine out of service in the same
            // call, rather than leaving it on the board for someone to notice.
            'status' => $updated->status,
            'unavailable_reason' => $this->machines->unavailableReason($updated),
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
