<?php

declare(strict_types=1);

namespace App\Domain\Ops\Policies;

use App\Domain\Core\Models\Staff;
use App\Domain\Ops\Models\DialyzerUnit;

/**
 * Reprocessing is the renal technician's work -- the seeded role description
 * says so in as many words: "Machines, water, reprocessing".
 *
 * Borrowing the treatment-session policy for this was wrong: booking the floor
 * and reprocessing a dialyzer are different jobs done by different people, and
 * a technician is in neither of the roles that schedule patients.
 */
final class DialyzerUnitPolicy
{
    /** Anyone on duty may look one up; a nurse scans the barcode at the chair. */
    public function viewAny(Staff $staff): bool
    {
        return (bool) $staff->is_active;
    }

    public function view(Staff $staff, DialyzerUnit $unit): bool
    {
        return (bool) $staff->is_active;
    }

    /** Recording a reprocessing cycle, and with it condemning a unit. */
    public function reprocess(Staff $staff): bool
    {
        return $staff->is_active
            && ($staff->hasRole('technician') || $staff->hasRole('head_nurse') || $staff->hasRole('admin'));
    }
}
