<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Core\Models\Patient;
use App\Domain\Core\Models\Staff;
use App\Domain\Core\Services\FacilityCalendar;
use App\Support\Exceptions\DomainRuleException;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * What the patient owes, after the payer has taken its share.
 *
 * A claim and an invoice answer different questions. The claim is what the payer
 * is asked for; the invoice is what is left. For a package with no-balance
 * billing they cancel out and the patient owes nothing -- which is the point of
 * that flag, and getting it wrong bills a patient for something the law says is
 * already covered.
 *
 * Money is DECIMAL(12,2) throughout and arithmetic goes through bcmath. A float
 * here rounds someone's bill.
 */
final class InvoiceService
{
    public function __construct(private readonly FacilityCalendar $calendar) {}

    /**
     * Build a draft invoice for a set of sessions.
     *
     * Each session becomes a line at the service item's price. Where a session
     * has already been claimed, the payer's share is subtracted -- and if the
     * program forbids balance billing, the patient's share is zero regardless of
     * what the line totals came to.
     *
     * Sessions are named by public_id; the row id never leaves the server.
     *
     * @param  list<string>  $sessionPublicIds
     */
    public function draftFor(Patient $patient, array $sessionPublicIds, Staff $actor): Invoice
    {
        $sessionPublicIds = array_values(array_unique($sessionPublicIds));

        if ($sessionPublicIds === []) {
            throw new DomainRuleException('No sessions were given to invoice.');
        }

        return DB::transaction(function () use ($patient, $sessionPublicIds, $actor): Invoice {
            $sessions = DB::table('treatment_sessions as ts')
                ->leftJoin('claim_sessions as cs', 'cs.session_id', '=', 'ts.id')
                ->leftJoin('claims as c', 'c.id', '=', 'cs.claim_id')
                ->leftJoin('benefit_programs as bp', 'bp.id', '=', 'c.program_id')
                ->whereIn('ts.public_id', $sessionPublicIds)
                ->lockForUpdate()
                ->orderBy('ts.session_date')
                ->get([
                    'ts.id', 'ts.public_id', 'ts.patient_id', 'ts.session_date',
                    'ts.status', 'ts.locked_at', 'ts.is_billable', 'ts.modality',
                    'cs.amount as payer_amount', 'bp.no_balance_billing',
                ]);

            if ($sessions->count() !== count($sessionPublicIds)) {
                throw new DomainRuleException('One or more of those sessions does not exist.');
            }

            foreach ($sessions as $session) {
                $this->assertInvoiceable($session, $patient);
            }

            $invoice = Invoice::create([
                'invoice_no' => $this->nextInvoiceNumber(Carbon::now()),
                'patient_id' => $patient->id,
                'issued_on' => $this->calendar->todayString(),
                'status' => 'draft',
                'created_by' => $actor->id,
            ]);

            $subtotal = '0.00';
            $payerCovered = '0.00';
            $patientDue = '0.00';

            foreach ($sessions as $index => $session) {
                // Priced by modality off the service_items price list, not by a
                // literal: HD and HDF are different treatments and cost differently.
                $item = $this->serviceItemForModality((string) $session->modality);

                if ($item === null) {
                    throw new DomainRuleException(
                        "No active price-list entry for modality {$session->modality}; add one to service_items."
                    );
                }

                $unitPrice = (string) $item->unit_price;

                DB::table('invoice_lines')->insert([
                    'invoice_id' => $invoice->id,
                    'session_id' => $session->id,
                    'service_item_id' => $item->id,
                    'description' => $item->name.' — '.$session->session_date,
                    'qty' => 1,
                    'unit_price' => $unitPrice,
                    'discount' => 0,
                    'sort_order' => $index,
                ]);

                $line = $this->decimal($unitPrice);
                $payer = $this->decimal($session->payer_amount ?? '0.00');

                // No-balance billing belongs to the package a session was
                // CLAIMED under, so it is applied line by line. It used to be
                // applied to the whole invoice as soon as any one line carried
                // it: an invoice with one claimed session and one unclaimed one
                // zeroed the unclaimed charge too and called it payer-covered,
                // though no payer had been asked for it.
                if ((int) ($session->no_balance_billing ?? 0) === 1) {
                    // The package forbids charging the patient the difference.
                    // Billing it anyway is the failure this flag exists to prevent.
                    $payer = $line;
                }

                // A payer share above the list price leaves the patient owing
                // nothing on that line; the surplus is a payer reconciliation
                // matter, not a credit to be handed back here.
                $patient = bccomp($payer, $line, 2) >= 0 ? '0.00' : bcsub($line, $payer, 2);

                $subtotal = bcadd($subtotal, $line, 2);
                $payerCovered = bcadd($payerCovered, $payer, 2);
                $patientDue = bcadd($patientDue, $patient, 2);
            }

            $invoice->forceFill([
                'subtotal' => $subtotal,
                'payer_covered' => $payerCovered,
                'patient_due' => $patientDue,
                // `balance` is generated: patient_due - amount_paid.
            ])->save();

            return $invoice->refresh();
        });
    }

    /**
     * Record a payment and move the invoice's status to match.
     *
     * The balance is a generated column, so it is never written -- it is read
     * back after the payment lands and the status follows from it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordPayment(Invoice $invoice, array $attributes, Staff $actor): Invoice
    {
        return DB::transaction(function () use ($invoice, $attributes, $actor): Invoice {
            // Re-read under a lock: two cashiers taking the last of a balance at
            // once must not both pass the overpayment check below.
            $invoice = $this->locked($invoice);

            if (in_array($invoice->status, ['void', 'written_off'], true)) {
                throw new DomainRuleException("Invoice {$invoice->invoice_no} is {$invoice->status} and takes no payments.");
            }

            // Two places, as the column stores it, so a refusal quotes "7000.00".
            $amount = bcadd($this->decimal($attributes['amount']), '0', 2);
            $received = $this->decimal($invoice->amount_paid ?? '0.00');
            $balance = $this->decimal($invoice->balance ?? '0.00');

            // Nothing holds a credit balance in this system, so more than is owed
            // has nowhere to go -- and 63,500 keyed for 6,350 would otherwise mark
            // the invoice paid with a large negative balance nobody asked for.
            if (bccomp($amount, '0.00', 2) > 0 && bccomp($amount, $balance, 2) > 0) {
                throw new DomainRuleException(sprintf(
                    'A payment of %s is more than the %s still owed on invoice %s. Record the amount applied to '
                    .'this invoice; this system does not hold a credit.',
                    $amount,
                    $balance,
                    $invoice->invoice_no,
                ));
            }

            // A refund gives back money that was received, and no more.
            if (bccomp($amount, '0.00', 2) < 0 && bccomp(bcadd($received, $amount, 2), '0.00', 2) < 0) {
                throw new DomainRuleException(sprintf(
                    'A refund of %s is more than the %s received on invoice %s.',
                    ltrim($amount, '-'),
                    $received,
                    $invoice->invoice_no,
                ));
            }

            DB::table('payments')->insert([
                'invoice_id' => $invoice->id,
                'paid_on' => $attributes['paid_on'] ?? $this->calendar->todayString(),
                'amount' => $amount,
                'method' => $attributes['method'],
                'reference_no' => $attributes['reference_no'] ?? null,
                'payer_id' => $attributes['payer_id'] ?? null,
                'received_by' => $actor->id,
                'notes' => $attributes['notes'] ?? null,
                'created_at' => Carbon::now(),
            ]);

            $paid = $this->decimal(DB::table('payments')->where('invoice_id', $invoice->id)->sum('amount'));

            $invoice->forceFill(['amount_paid' => $paid])->save();
            $invoice->refresh();

            $balance = $this->decimal($invoice->balance ?? '0.00');

            $invoice->forceFill([
                // Follows the money both ways. A full refund used to leave the
                // status at `paid` over a balance of the whole amount -- a
                // status disagreeing with its own figures. An invoice that has
                // taken a payment has been issued, so that is where it returns.
                'status' => match (true) {
                    bccomp($balance, '0.00', 2) <= 0 => 'paid',
                    bccomp($paid, '0.00', 2) > 0 => 'partially_paid',
                    default => 'issued',
                },
            ])->save();

            return $invoice->refresh();
        });
    }

    /**
     * Hand a draft to the patient. The issue date is the day it is issued, not
     * the day it was drafted, because it is the date on the patient's copy.
     */
    public function issue(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice): Invoice {
            $invoice = $this->locked($invoice);

            if ($invoice->status !== 'draft') {
                throw new DomainRuleException(
                    "Invoice {$invoice->invoice_no} is {$invoice->status}; only a draft is issued."
                );
            }

            $invoice->forceFill([
                'status' => 'issued',
                'issued_on' => $this->calendar->todayString(),
            ])->save();

            return $invoice->refresh();
        });
    }

    /**
     * Void an invoice raised in error, freeing its sessions to be invoiced again.
     *
     * Without this, an invoice drafted over the wrong sessions held them for
     * good: assertInvoiceable() refuses a session already on any invoice that is
     * not void, and nothing could make one void. The lines stay (they are what
     * the voided copy said), and the reason goes onto the invoice's own notes as
     * well as into audit_logs through the Auditable trait.
     *
     * Refused while money sits on it. A void invoice holding a payment is money
     * nobody can find; refund it first, as a negative payment, then void.
     */
    public function void(Invoice $invoice, string $reason, Staff $actor): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason, $actor): Invoice {
            $invoice = $this->locked($invoice);

            if (in_array($invoice->status, ['void', 'written_off'], true)) {
                throw new DomainRuleException("Invoice {$invoice->invoice_no} is already {$invoice->status}.");
            }

            $received = $this->decimal($invoice->amount_paid ?? '0.00');

            if (bccomp($received, '0.00', 2) !== 0) {
                throw new DomainRuleException(sprintf(
                    'Invoice %s has %s received against it. Refund that first (a negative payment), then void it '
                    .'-- a void invoice holding money is money nobody can find.',
                    $invoice->invoice_no,
                    $received,
                ));
            }

            $line = sprintf('VOIDED %s by %s: %s', $this->calendar->todayString(), $actor->full_name, $reason);

            $invoice->forceFill([
                'status' => 'void',
                'notes' => trim(($invoice->notes ?? '')."\n".$line),
            ])->save();

            return $invoice->refresh();
        });
    }

    /**
     * Sessions that could go on a new invoice for this patient: signed,
     * billable, and not already on an invoice that stands.
     *
     * Each carries what a biller needs to decide before drafting -- the claim it
     * is on and where that claim stands, and the list price it will be charged
     * at. A session with no price-list entry is shown with none, and drafting it
     * is refused, rather than priced at a guess.
     *
     * @return list<array<string, mixed>>
     */
    public function invoiceable(Patient $patient, ?CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = DB::table('treatment_sessions as ts')
            ->leftJoin('claim_sessions as cs', 'cs.session_id', '=', 'ts.id')
            ->leftJoin('claims as c', 'c.id', '=', 'cs.claim_id')
            ->where('ts.patient_id', $patient->id)
            ->whereNotNull('ts.locked_at')
            ->where('ts.is_billable', 1)
            ->when($from !== null, fn ($query) => $query->where('ts.session_date', '>=', $from?->toDateString()))
            ->where('ts.session_date', '<=', $to->toDateString())
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('invoice_lines as il')
                    ->join('invoices as i', 'i.id', '=', 'il.invoice_id')
                    ->whereColumn('il.session_id', 'ts.id')
                    ->where('i.status', '<>', 'void');
            })
            ->orderBy('ts.session_date')
            ->get([
                'ts.public_id', 'ts.session_date', 'ts.modality', 'ts.status',
                'c.claim_no', 'c.status as claim_status', 'cs.amount as payer_amount',
            ]);

        $sessions = [];

        foreach ($rows as $row) {
            $item = $this->serviceItemForModality((string) $row->modality);

            $sessions[] = [
                'public_id' => $row->public_id,
                'session_date' => (string) $row->session_date,
                'modality' => $row->modality,
                'status' => $row->status,
                'claim_no' => $row->claim_no,
                'claim_status' => $row->claim_status,
                'payer_amount' => $row->payer_amount,
                'list_price' => $item?->unit_price,
                'service_item' => $item?->name,
            ];
        }

        return $sessions;
    }

    /**
     * The invoices list: newest first, optionally one status, optionally a
     * number, MRN or name.
     *
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function search(?string $status, ?string $term, int $perPage): LengthAwarePaginator
    {
        return DB::table('invoices as i')
            ->join('patients as p', 'p.id', '=', 'i.patient_id')
            ->when($status !== null, fn ($query) => $query->where('i.status', $status))
            ->when($term !== null, function ($query) use ($term): void {
                $like = '%'.addcslashes((string) $term, '%_\\').'%';

                $query->where(function ($inner) use ($like): void {
                    $inner->where('i.invoice_no', 'like', $like)
                        ->orWhere('p.mrn', 'like', $like)
                        ->orWhere('p.full_name', 'like', $like);
                });
            })
            ->orderByDesc('i.issued_on')
            ->orderByDesc('i.id')
            ->paginate(max(1, min($perPage, 100)), [
                'i.invoice_no', 'i.issued_on', 'i.status', 'i.currency',
                'i.subtotal', 'i.payer_covered', 'i.patient_due', 'i.amount_paid', 'i.balance',
                'p.public_id as patient_public_id', 'p.mrn', 'p.full_name',
            ]);
    }

    /**
     * Everything the invoice screen shows, and what may be done to it next.
     *
     * @return array<string, mixed>
     */
    public function detail(Invoice $invoice): array
    {
        $patient = DB::table('patients')->where('id', $invoice->patient_id)->first(['public_id', 'mrn', 'full_name']);
        $received = $this->decimal($invoice->amount_paid ?? '0.00');
        $open = ! in_array($invoice->status, ['void', 'written_off'], true);

        return [
            'invoice_no' => $invoice->invoice_no,
            'issued_on' => $invoice->issued_on->toDateString(),
            'due_on' => $invoice->due_on?->toDateString(),
            'status' => $invoice->status,
            'currency' => $invoice->currency,
            'patient' => $patient === null ? null : [
                'public_id' => $patient->public_id,
                'mrn' => $patient->mrn,
                'full_name' => $patient->full_name,
            ],
            // Money stays a string all the way to the client. A float here is a
            // rounding error on someone's bill.
            'subtotal' => $invoice->subtotal,
            'discount' => $invoice->discount,
            'tax' => $invoice->tax,
            'payer_covered' => $invoice->payer_covered,
            'patient_due' => $invoice->patient_due,
            'amount_paid' => $invoice->amount_paid,
            // Generated by MySQL: patient_due - amount_paid.
            'balance' => $invoice->balance,
            'notes' => $invoice->notes,
            'can_issue' => $invoice->status === 'draft',
            'can_void' => $open && bccomp($received, '0.00', 2) === 0,
            'takes_payments' => $open,
            'lines' => DB::table('invoice_lines as il')
                ->leftJoin('treatment_sessions as ts', 'ts.id', '=', 'il.session_id')
                ->leftJoin('claim_sessions as cs', 'cs.session_id', '=', 'il.session_id')
                ->leftJoin('claims as c', 'c.id', '=', 'cs.claim_id')
                ->where('il.invoice_id', $invoice->id)
                ->orderBy('il.sort_order')
                ->get([
                    'il.description', 'il.qty', 'il.unit_price', 'il.discount', 'il.line_total',
                    'ts.public_id as session_public_id', 'ts.session_date',
                    'c.claim_no', 'c.status as claim_status',
                ])
                ->all(),
            'payments' => DB::table('payments as pay')
                ->leftJoin('staff as s', 's.id', '=', 'pay.received_by')
                ->where('pay.invoice_id', $invoice->id)
                ->orderBy('pay.paid_on')
                ->orderBy('pay.id')
                ->get(['pay.paid_on', 'pay.amount', 'pay.method', 'pay.reference_no', 'pay.notes', 's.full_name as received_by'])
                ->all(),
        ];
    }

    /** The invoice row, re-read under a lock for the rest of the transaction. */
    private function locked(Invoice $invoice): Invoice
    {
        $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->first();

        if (! $locked instanceof Invoice) {
            throw new DomainRuleException("Invoice {$invoice->invoice_no} disappeared while it was being updated.");
        }

        return $locked;
    }

    /**
     * Narrow a DECIMAL column to a numeric string.
     *
     * These values come from DECIMAL(12,2) columns so they are always numeric,
     * but nothing in the type system says so -- and bcmath silently treats a
     * non-numeric string as zero, which on an invoice means a line quietly
     * priced at nothing. Better to refuse.
     *
     * @return numeric-string
     */
    private function decimal(mixed $value): string
    {
        $string = (string) $value;

        if (! is_numeric($string)) {
            throw new DomainRuleException("Expected a decimal amount but got: {$string}");
        }

        return $string;
    }

    /** The price list entry for a service code. */
    public function serviceItemFor(string $code): ?stdClass
    {
        $row = DB::table('service_items')->where('code', $code)->where('is_active', 1)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * The price list entry for a treatment modality.
     *
     * Mapped explicitly rather than by string-mangling the modality into a code:
     * an unmapped modality should fail loudly and be added to the price list,
     * not silently fall back to the haemodialysis rate.
     */
    public function serviceItemForModality(string $modality): ?stdClass
    {
        $code = match ($modality) {
            'hd', 'ihd_acute', 'sled' => 'HD-SESSION',
            'hdf', 'hf' => 'HDF-SESSION',
            default => null,
        };

        return $code === null ? null : $this->serviceItemFor($code);
    }

    private function assertInvoiceable(stdClass $session, Patient $patient): void
    {
        if ((int) $session->patient_id !== (int) $patient->id) {
            throw new DomainRuleException(
                "Session {$session->public_id} belongs to a different patient and cannot go on this invoice."
            );
        }

        if ($session->locked_at === null) {
            throw new DomainRuleException(
                "Session {$session->public_id} is not signed. Only a locked record may be invoiced."
            );
        }

        if ((int) $session->is_billable !== 1) {
            throw new DomainRuleException("Session {$session->public_id} is marked not billable.");
        }

        $existing = DB::table('invoice_lines')
            ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
            ->where('invoice_lines.session_id', $session->id)
            ->whereNotIn('invoices.status', ['void'])
            ->value('invoices.invoice_no');

        if ($existing !== null) {
            throw new DomainRuleException("Session {$session->public_id} is already on invoice {$existing}.");
        }
    }

    private function nextInvoiceNumber(CarbonInterface $issuedOn): string
    {
        $prefix = 'INV-'.$issuedOn->format('Ym').'-';

        $last = DB::table('invoices')
            ->where('invoice_no', 'like', $prefix.'%')
            ->orderByDesc('invoice_no')
            ->value('invoice_no');

        $next = $last === null ? 1 : ((int) substr((string) $last, -4)) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
