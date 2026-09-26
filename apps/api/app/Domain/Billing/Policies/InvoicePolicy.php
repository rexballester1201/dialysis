<?php

declare(strict_types=1);

namespace App\Domain\Billing\Policies;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Core\Models\Staff;
use Illuminate\Auth\Access\Response;

/**
 * The same people as claims -- billing officers and administrators -- but its
 * own policy rather than borrowing ClaimPolicy.
 *
 * Authorising one model against another's policy works right up until the two
 * need to differ, and then it fails somewhere far from where it was decided.
 */
final class InvoicePolicy
{
    public function viewAny(Staff $staff): bool
    {
        return $staff->is_active && $this->isBilling($staff);
    }

    public function view(Staff $staff, Invoice $invoice): bool
    {
        return $staff->is_active && $this->isBilling($staff);
    }

    public function create(Staff $staff): bool
    {
        return $staff->is_active && $this->isBilling($staff);
    }

    /**
     * Taking money against an invoice.
     *
     * A void or written-off invoice is refused as a 409, not a 403: the person
     * asking is allowed to take payments, and "your role does not allow that"
     * would send them to an administrator about a record that is simply closed.
     */
    public function pay(Staff $staff, Invoice $invoice): Response
    {
        if (! ($staff->is_active && $this->isBilling($staff))) {
            return Response::deny();
        }

        if (in_array($invoice->status, ['void', 'written_off'], true)) {
            return Response::denyWithStatus(
                409,
                "Invoice {$invoice->invoice_no} is {$invoice->status} and takes no payments.",
            );
        }

        return Response::allow();
    }

    /** Issuing a draft, or voiding one raised in error. The service decides which are allowed. */
    public function update(Staff $staff, Invoice $invoice): bool
    {
        return $staff->is_active && $this->isBilling($staff);
    }

    /**
     * An invoice is voided, never deleted: it may already be in a patient's
     * hands, and the line items reference sessions.
     */
    public function delete(Staff $staff, Invoice $invoice): bool
    {
        return false;
    }

    private function isBilling(Staff $staff): bool
    {
        return $staff->hasRole('billing') || $staff->hasRole('admin');
    }
}
