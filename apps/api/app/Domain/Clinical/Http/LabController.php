<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Http;

use App\Domain\Clinical\Http\Requests\FileLabResultsRequest;
use App\Domain\Clinical\Http\Requests\OrderLabPanelRequest;
use App\Domain\Clinical\Models\LabOrder;
use App\Domain\Clinical\Services\LabService;
use App\Domain\Core\Models\Patient;
use App\Domain\Core\Models\Staff;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Monthly bloods.
 *
 * Viewing and filing follow the patient's own policy -- if you may read the
 * chart you may read the labs, and if you may update the patient you may file a
 * result. Ordering is narrower and is checked explicitly: committing the unit to
 * drawing blood is a prescribing decision.
 */
final class LabController extends Controller
{
    public function __construct(private readonly LabService $labs) {}

    /** The catalogue. Reference data, readable by anyone who may see a chart. */
    public function catalogue(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Patient::class);

        $panel = $request->string('panel')->toString() ?: null;

        return response()->json(['tests' => $this->labs->catalogue($panel)]);
    }

    public function orders(Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        return response()->json([
            'orders' => LabOrder::query()
                ->where('patient_id', $patient->id)
                ->orderByDesc('ordered_on')
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function order(OrderLabPanelRequest $request, Patient $patient): JsonResponse
    {
        $this->authorize('update', $patient);
        $actor = $this->actor($request);

        // Ordering commits the unit to a venepuncture. Nurses chart, they do not
        // decide what gets drawn.
        abort_unless(
            $actor->hasRole('nephrologist') || $actor->hasRole('head_nurse') || $actor->hasRole('admin'),
            403,
            'Only a nephrologist or the head nurse can order a lab panel.',
        );

        return response()->json(
            ['order' => $this->labs->order($patient, $request->validated(), $actor)],
            Response::HTTP_CREATED,
        );
    }

    public function transition(Request $request, LabOrder $order): JsonResponse
    {
        $patient = Patient::query()->whereKey($order->patient_id)->firstOrFail();
        $this->authorize('update', $patient);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:collected,resulted,cancelled'],
        ]);

        return response()->json([
            'order' => $this->labs->transition($order, $validated['status'], $this->actor($request)),
        ]);
    }

    /**
     * A patient's results.
     *
     * `latest=1` returns the most recent value per test, which is what a chart
     * shows; without it, the full history for trending.
     */
    public function results(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $testCode = $request->string('test_code')->toString() ?: null;

        return response()->json([
            'results' => $request->boolean('latest')
                ? $this->labs->latestFor($patient)
                : $this->labs->resultsFor($patient, $testCode),
        ]);
    }

    /**
     * File a panel.
     *
     * 200, not 201, even on success: the batch is adjudicated per row and the
     * body carries a verdict for each, so the interesting information is never
     * the status code. A row rejected for a bad code does not stop the rest.
     */
    public function fileResults(FileLabResultsRequest $request, Patient $patient): JsonResponse
    {
        $this->authorize('update', $patient);

        $validated = $request->validated();
        $order = null;

        if (($validated['order_id'] ?? null) !== null) {
            $order = LabOrder::query()->whereKey($validated['order_id'])->firstOrFail();
        }

        return response()->json(
            $this->labs->fileResults($patient, $validated['results'], $this->actor($request), $order)
        );
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
