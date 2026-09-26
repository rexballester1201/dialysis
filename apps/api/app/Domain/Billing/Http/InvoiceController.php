<?php

declare(strict_types=1);

namespace App\Domain\Billing\Http;

use App\Domain\Billing\Http\Requests\DraftInvoiceRequest;
use App\Domain\Billing\Http\Requests\RecordPaymentRequest;
use App\Domain\Billing\Http\Requests\VoidInvoiceRequest;
use App\Domain\Billing\Http\Resources\InvoiceSummaryResource;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Services\InvoiceService;
use App\Domain\Core\Models\Patient;
use App\Domain\Core\Models\Staff;
use App\Domain\Core\Services\FacilityCalendar;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * What the patient owes once the payer's share is taken off.
 *
 * Every write answers with the same detail the invoice screen reads.
 */
final class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly FacilityCalendar $calendar,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        return InvoiceSummaryResource::collection($this->invoices->search(
            $request->string('status')->toString() ?: null,
            $request->string('q')->trim()->toString() ?: null,
            (int) $request->integer('per_page', 25),
        ))->response();
    }

    /** Sessions that could go on a new invoice, with the claim each is on and its list price. */
    public function invoiceable(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('create', Invoice::class);

        return response()->json([
            'sessions' => $this->invoices->invoiceable($patient, $request->date('from'), $request->date('to') ?? $this->calendar->today()),
        ]);
    }

    public function store(DraftInvoiceRequest $request, Patient $patient): JsonResponse
    {
        $this->authorize('create', Invoice::class);

        /** @var list<string> $ids */
        $ids = array_values(array_map('strval', $request->array('session_public_ids')));

        $invoice = $this->invoices->draftFor($patient, $ids, $this->actor($request));

        return response()->json($this->invoices->detail($invoice), Response::HTTP_CREATED);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);

        return response()->json($this->invoices->detail($invoice));
    }

    public function issue(Invoice $invoice): JsonResponse
    {
        $this->authorize('update', $invoice);

        return response()->json($this->invoices->detail($this->invoices->issue($invoice)));
    }

    public function void(VoidInvoiceRequest $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('update', $invoice);

        $reason = $request->string('reason')->trim()->toString();

        // AuditObserver reads its reason from the container's request. This
        // FormRequest is built as a copy of that one which shares only the JSON
        // body, so merging into $request reaches the audit row for a JSON call
        // and silently misses it for a form-encoded one. Merge into the request
        // the observer actually reads.
        request()->merge(['_audit_reason' => $reason]);

        return response()->json($this->invoices->detail(
            $this->invoices->void($invoice, $reason, $this->actor($request)),
        ));
    }

    public function pay(RecordPaymentRequest $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('pay', $invoice);

        $updated = $this->invoices->recordPayment($invoice, $request->validated(), $this->actor($request));

        return response()->json($this->invoices->detail($updated), Response::HTTP_CREATED);
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
