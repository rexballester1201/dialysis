<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Policies;

use App\Domain\Clinical\Models\TreatmentSession;
use App\Domain\Core\Models\Staff;
use Illuminate\Auth\Access\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class TreatmentSessionPolicy
{
    public function view(Staff $staff, TreatmentSession $session): bool
    {
        return $staff->is_active;
    }

    /** Reading the board is open to anyone on duty. */
    public function viewAny(Staff $staff): bool
    {
        return (bool) $staff->is_active;
    }

    /**
     * Putting a patient in a chair is a charge-nurse function. A physician does
     * not book the floor and a technician does not either.
     */
    public function create(Staff $staff): bool
    {
        return $staff->is_active
            && ($staff->hasRole('head_nurse') || $staff->hasRole('nurse') || $staff->hasRole('admin'));
    }

    /**
     * Charting is open to bedside staff, but only while the record is unlocked.
     *
     * The two refusals are different facts and are reported as such. "You are a
     * technician" is 403 and permanent. "This record was signed" is 409 and
     * about timing -- a tablet that was offline when the record locked needs to
     * tell the nurse to use the amendment path, not that she lacks permission.
     */
    public function chart(Staff $staff, TreatmentSession $session): Response
    {
        if (! $staff->is_active) {
            return Response::deny('This account is not active.');
        }

        if (! ($staff->hasRole('nurse') || $staff->hasRole('head_nurse') || $staff->hasRole('nephrologist'))) {
            return Response::deny('Only bedside clinical staff may chart a treatment.');
        }

        if ($session->isLocked()) {
            return Response::denyWithStatus(
                SymfonyResponse::HTTP_CONFLICT,
                "Session {$session->public_id} was signed and locked at "
                .($session->locked_at?->toIso8601String() ?? 'an unknown time')
                .'. Corrections go through the amendment path.'
            );
        }

        return Response::allow();
    }

    public function signAsNurse(Staff $staff, TreatmentSession $session): bool
    {
        return ! $session->isLocked()
            && $session->nurse_signed_at === null
            && ($staff->hasRole('nurse') || $staff->hasRole('head_nurse'));
    }

    public function signAsPhysician(Staff $staff, TreatmentSession $session): bool
    {
        return ! $session->isLocked()
            && $session->physician_signed_at === null
            && $staff->hasRole('nephrologist');
    }

    /** Only a physician may amend a signed record, and never their own countersign. */
    public function amend(Staff $staff, TreatmentSession $session): bool
    {
        return $session->isLocked() && $staff->hasRole('nephrologist');
    }

    public function bill(Staff $staff, TreatmentSession $session): bool
    {
        return $staff->hasRole('billing') || $staff->hasRole('admin');
    }
}
