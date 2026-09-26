<?php

declare(strict_types=1);

namespace App\Domain\Core\Policies;

use App\Domain\Core\Models\Patient;
use App\Domain\Core\Models\Staff;

/**
 * Built on the same principle as TreatmentSessionPolicy: permission is
 * conditional on the state of the record, not on the role alone.
 *
 * A patient who has died or transferred out is not editable by the clinical
 * staff who could edit them yesterday -- correcting such a record is a records
 * function, and it leaves an audit trail either way.
 */
final class PatientPolicy
{
    /**
     * Statuses after which the chart is closed to routine clinical editing.
     */
    private const CLOSED_STATUSES = [
        'deceased',
        'transferred_out',
        'transplanted',
        'recovered_function',
        'lost_to_followup',
        'discontinued',
    ];

    /** Any active staff member may read a chart. Reading it is logged. */
    public function view(Staff $staff, Patient $patient): bool
    {
        return (bool) $staff->is_active;
    }

    public function viewAny(Staff $staff): bool
    {
        return (bool) $staff->is_active;
    }

    public function create(Staff $staff): bool
    {
        return $staff->is_active
            && ($staff->hasRole('records') || $staff->hasRole('head_nurse') || $staff->hasRole('admin'));
    }

    public function update(Staff $staff, Patient $patient): bool
    {
        if (! $staff->is_active) {
            return false;
        }

        if (in_array($patient->status, self::CLOSED_STATUSES, true)) {
            return $staff->hasRole('records') || $staff->hasRole('admin');
        }

        return $staff->hasRole('records')
            || $staff->hasRole('head_nurse')
            || $staff->hasRole('nurse')
            || $staff->hasRole('nephrologist')
            || $staff->hasRole('admin');
    }

    /**
     * Soft delete only, and only by an administrator.
     *
     * A patient record is never hard-deleted (CLAUDE.md), so there is
     * deliberately no forceDelete ability here for anyone to reach for.
     */
    public function delete(Staff $staff, Patient $patient): bool
    {
        return $staff->is_active && $staff->hasRole('admin');
    }

    /** Writing a prescription is a physician act, and only for a live chart. */
    public function prescribe(Staff $staff, Patient $patient): bool
    {
        return $staff->is_active
            && $staff->hasRole('nephrologist')
            && ! in_array($patient->status, self::CLOSED_STATUSES, true);
    }
}
