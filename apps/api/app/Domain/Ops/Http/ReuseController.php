<?php

declare(strict_types=1);

namespace App\Domain\Ops\Http;

use App\Domain\Core\Models\Patient;
use App\Domain\Core\Models\Staff;
use App\Domain\Ops\Http\Requests\ReprocessDialyzerRequest;
use App\Domain\Ops\Models\DialyzerUnit;
use App\Domain\Ops\Services\DialyzerReuseService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Dialyzer reuse: reprocessing, and whether a unit may be issued.
 *
 * Named in CLAUDE.md as the service side of invariant 8.
 */
final class ReuseController extends Controller
{
    public function __construct(private readonly DialyzerReuseService $reuse) {}

    /** Every unit belonging to a patient, with the reason any of them is unusable. */
    public function index(Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $units = DialyzerUnit::query()
            ->where('patient_id', $patient->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'minimum_tcv_pct' => DialyzerReuseService::MIN_TCV_PCT,
            'units' => $units->map(fn (DialyzerUnit $unit): array => [
                'label_code' => $unit->label_code,
                'status' => $unit->status,
                'use_count' => (int) $unit->use_count,
                'max_reuse_count' => $this->reuse->maxReuseCount($unit),
                'tcv_pct' => $unit->tcv_pct,
                'first_used_on' => $unit->first_used_on?->toDateString(),
                'discard_reason' => $unit->discard_reason,
                // Null means issuable. A UI can grey out a barcode before the
                // nurse scans it, using the logic that would refuse it anyway.
                'refusal_reason' => $this->reuse->refusalReason($unit, $patient),
            ])->all(),
        ]);
    }

    /**
     * Record a reprocessing cycle.
     *
     * The unit is condemned in the same call when the measured volume falls
     * below 80% of new, the reuse count is spent, or the technician rejected it.
     */
    public function reprocess(ReprocessDialyzerRequest $request, DialyzerUnit $dialyzer): JsonResponse
    {
        $this->authorize('reprocess', $dialyzer);

        $unit = $this->reuse->reprocess($dialyzer, $request->validated(), $this->actor($request));

        $patient = Patient::query()->find($unit->patient_id);

        return response()->json([
            'label_code' => $unit->label_code,
            'use_count' => (int) $unit->use_count,
            'tcv_pct' => $unit->tcv_pct,
            'status' => $unit->status,
            'discard_reason' => $unit->discard_reason,
            'issuable_again' => $patient !== null && $this->reuse->refusalReason($unit, $patient) === null,
        ], Response::HTTP_CREATED);
    }

    /**
     * The reprocessing history of one unit.
     *
     * This is the record that answers "what was this dialyzer's volume the last
     * time it went on a patient", which is the question an incident review asks.
     */
    public function history(DialyzerUnit $dialyzer): JsonResponse
    {
        $patient = Patient::query()->findOrFail($dialyzer->patient_id);

        $this->authorize('view', $patient);

        return response()->json([
            'label_code' => $dialyzer->label_code,
            'status' => $dialyzer->status,
            'tcv_pct' => $dialyzer->tcv_pct,
            'cycles' => DB::table('dialyzer_reprocess_logs')
                ->where('dialyzer_unit_id', $dialyzer->id)
                ->orderBy('use_number')
                ->get([
                    'use_number', 'reprocessed_at', 'method', 'germicide',
                    'tcv_ml', 'tcv_pct_of_initial', 'pressure_test_passed',
                    'fibre_bundle_ok', 'visual_ok', 'residual_test_passed',
                    'accepted', 'reject_reason',
                ]),
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
