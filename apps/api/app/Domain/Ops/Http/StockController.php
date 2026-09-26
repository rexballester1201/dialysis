<?php

declare(strict_types=1);

namespace App\Domain\Ops\Http;

use App\Domain\Core\Models\Staff;
use App\Domain\Ops\Http\Requests\IssueStockRequest;
use App\Domain\Ops\Http\Requests\ReceiveStockRequest;
use App\Domain\Ops\Services\StockService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Consumables in and out.
 *
 * The lot balances come from v_stock_on_hand, which sums the lots -- and the
 * lots are only ever moved by the stock_transactions_ai trigger, so the count
 * and the movement history cannot drift apart.
 */
final class StockController extends Controller
{
    public function __construct(private readonly StockService $stock) {}

    public function index(): JsonResponse
    {
        $this->authorize('water.view');

        return response()->json([
            'items' => DB::table('v_stock_on_hand')->orderBy('name')->get(),
        ]);
    }

    /** The lots behind one item, in the order they would actually be issued. */
    public function lots(int $item): JsonResponse
    {
        $this->authorize('water.view');

        return response()->json([
            'issuable' => $this->stock->issuableLots($item),
            'all' => DB::table('stock_lots')
                ->where('item_id', $item)
                ->orderByRaw('expiry_date IS NULL, expiry_date')
                ->get(['id', 'lot_no', 'expiry_date', 'qty_received', 'qty_on_hand', 'is_quarantined']),
        ]);
    }

    public function receive(ReceiveStockRequest $request): JsonResponse
    {
        $this->authorize('water.record');

        $lot = $this->stock->receive($request->validated(), $this->actor($request));

        return response()->json([
            'lot_no' => $lot->lot_no,
            'expiry_date' => $lot->expiry_date,
            // Written by the trigger, never by the service.
            'qty_on_hand' => $lot->qty_on_hand,
        ], Response::HTTP_CREATED);
    }

    /**
     * Issue to a session, first-expiry-first-out.
     *
     * Quarantined and expired lots are skipped entirely; if what is left cannot
     * cover the request, nothing is issued at all.
     */
    public function issue(IssueStockRequest $request): JsonResponse
    {
        $this->authorize('water.record');

        $drawn = $this->stock->issueToSession(
            (int) $request->integer('item_id'),
            $request->string('qty')->toString(),
            (int) $request->integer('session_id'),
            $request->filled('patient_id') ? (int) $request->integer('patient_id') : null,
            $this->actor($request),
        );

        return response()->json(['drawn_from' => $drawn], Response::HTTP_CREATED);
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
