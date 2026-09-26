<?php

declare(strict_types=1);

namespace App\Domain\Ops\Policies;

use App\Domain\Core\Models\Staff;

/**
 * Water compliance.
 *
 * Not a policy on a model -- the thing being authorised is the act of attesting
 * that the water was tested, which is the technician's. It is registered as a
 * pair of gates.
 */
final class WaterPolicy
{
    /** The clearance decides whether the unit dialyses; everyone may read it. */
    public function view(Staff $staff): bool
    {
        return (bool) $staff->is_active;
    }

    /**
     * Recording a check is an attestation that someone put a strip in the water.
     * It is limited to the people whose job that is.
     */
    public function record(Staff $staff): bool
    {
        return $staff->is_active
            && ($staff->hasRole('technician') || $staff->hasRole('head_nurse') || $staff->hasRole('admin'));
    }
}
