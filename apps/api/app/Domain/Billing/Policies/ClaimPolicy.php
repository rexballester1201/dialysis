<?php

declare(strict_types=1);

namespace App\Domain\Billing\Policies;

use App\Domain\Billing\Models\Claim;
use App\Domain\Core\Models\Staff;

/**
 * Billing is the billing officer's, with the administrator as the fallback.
 *
 * Deliberately not open to clinical staff: a nurse who can generate a claim can
 * put a treatment in front of a payer, and separating that from charting is the
 * whole reason the roles exist.
 */
final class ClaimPolicy
{
    public function viewAny(Staff $staff): bool
    {
        return $staff->is_active && $this->isBilling($staff);
    }

    public function view(Staff $staff, Claim $claim): bool
    {
        return $staff->is_active && $this->isBilling($staff);
    }

    public function create(Staff $staff): bool
    {
        return $staff->is_active && $this->isBilling($staff);
    }

    /**
     * Moving a claim along its lifecycle, or recording the payer's remittance.
     * Which moves are allowed from where is BenefitLedger's to decide.
     */
    public function transition(Staff $staff, Claim $claim): bool
    {
        return $staff->is_active && $this->isBilling($staff);
    }

    /**
     * A claim is never deleted. It is voided, which leaves the record and the
     * status history in place for the payer's own audit.
     */
    public function delete(Staff $staff, Claim $claim): bool
    {
        return false;
    }

    private function isBilling(Staff $staff): bool
    {
        return $staff->hasRole('billing') || $staff->hasRole('admin');
    }
}
