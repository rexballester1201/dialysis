<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Policies;

use App\Domain\Clinical\Models\HdPrescription;
use App\Domain\Core\Models\Staff;
use Illuminate\Support\Carbon;

/**
 * Same pattern as TreatmentSessionPolicy: the record's state decides.
 *
 * A prescription that has already been superseded is history. Editing history
 * in place would silently change what a past session was dialysed against, so
 * a superseded version is read-only to everyone and a new version is the only
 * way forward -- which is what PrescriptionService::revise() does.
 */
final class PrescriptionPolicy
{
    public function viewAny(Staff $staff): bool
    {
        return (bool) $staff->is_active;
    }

    public function view(Staff $staff, HdPrescription $prescription): bool
    {
        return (bool) $staff->is_active;
    }

    public function create(Staff $staff): bool
    {
        return $staff->is_active && $staff->hasRole('nephrologist');
    }

    /** Only the open version, and only by a physician. */
    public function update(Staff $staff, HdPrescription $prescription): bool
    {
        return $staff->is_active
            && $staff->hasRole('nephrologist')
            && $this->isCurrent($prescription);
    }

    /** Revising means closing this version and opening the next one. */
    public function revise(Staff $staff, HdPrescription $prescription): bool
    {
        return $this->update($staff, $prescription);
    }

    /**
     * A prescription is never deleted. Sessions reference the version they ran
     * under, so removing one would orphan the clinical record of what was
     * actually delivered.
     */
    public function delete(Staff $staff, HdPrescription $prescription): bool
    {
        return false;
    }

    private function isCurrent(HdPrescription $prescription): bool
    {
        return $prescription->effective_to === null
            || Carbon::parse($prescription->effective_to)->isFuture();
    }
}
