<?php

declare(strict_types=1);

namespace App\Domain\Core\Services;

use App\Domain\Core\Models\Patient;
use App\Domain\Core\Models\Staff;
use App\Support\Exceptions\DomainRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The patient registry.
 *
 * Two rules live here rather than in a controller because both have a clinical
 * consequence and both must survive a route being added by someone in a hurry:
 *
 *  1. A status never changes without a `patient_status_histories` row. "When did
 *     this patient transfer out?" is a question the chart has to be able to
 *     answer years later, and `patients.status` alone cannot answer it.
 *  2. A dry weight is never a mutable column. It is an effective-dated row, so a
 *     session run last March is still interpretable against the target that was
 *     in force last March.
 */
final class PatientService
{
    public function __construct(private readonly FacilityCalendar $calendar) {}

    /**
     * Statuses after which the patient is no longer dialysing here. Reaching one
     * of these is a reportable event, not an edit.
     */
    private const TERMINAL_STATUSES = [
        'transferred_out',
        'transplanted',
        'recovered_function',
        'deceased',
        'lost_to_followup',
        'discontinued',
    ];

    /**
     * Register a patient and open their status history in one transaction.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function register(array $attributes, Staff $actor): Patient
    {
        return DB::transaction(function () use ($attributes, $actor): Patient {
            $patient = new Patient;
            $patient->fill($attributes + [
                'status' => $attributes['status'] ?? 'active',
                'status_changed_on' => $this->calendar->todayString(),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $patient->save();

            $this->recordStatus(
                $patient,
                (string) $patient->status,
                Carbon::now(),
                'registered',
                null,
                $actor,
            );

            return $patient;
        });
    }

    /**
     * Update demographic detail. Status is deliberately not settable here --
     * it goes through changeStatus() so the history cannot be skipped.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateDetails(Patient $patient, array $attributes, Staff $actor): Patient
    {
        unset($attributes['status'], $attributes['status_changed_on']);

        $patient->fill($attributes + ['updated_by' => $actor->id]);
        $patient->save();

        return $patient;
    }

    /**
     * Move a patient to a new status, writing the history row in the same
     * transaction as the column update. Neither can happen without the other.
     */
    public function changeStatus(
        Patient $patient,
        string $status,
        Carbon $effectiveOn,
        ?string $reason,
        ?string $destination,
        Staff $actor,
    ): Patient {
        if ($status === $patient->status) {
            throw new DomainRuleException("Patient is already {$status}.");
        }

        if (in_array($patient->status, self::TERMINAL_STATUSES, true) && $status === 'active') {
            // Re-admitting after a terminal status is a real event, but it is a
            // registration decision rather than a status edit -- it needs a
            // reason on the record, so refuse the silent version.
            if ($reason === null || trim($reason) === '') {
                throw new DomainRuleException(
                    "Re-activating a patient who is {$patient->status} requires a reason."
                );
            }
        }

        return DB::transaction(function () use ($patient, $status, $effectiveOn, $reason, $destination, $actor): Patient {
            $patient->fill([
                'status' => $status,
                'status_changed_on' => $effectiveOn->toDateString(),
                'updated_by' => $actor->id,
            ]);
            $patient->save();

            $this->recordStatus($patient, $status, $effectiveOn, $reason, $destination, $actor);

            return $patient;
        });
    }

    /**
     * Set the dry weight from a given date.
     *
     * Effective-dated by design: writing over the previous value would silently
     * change what every past session's IDWG meant.
     */
    public function setDryWeight(
        Patient $patient,
        string $weightKg,
        Carbon $effectiveFrom,
        ?string $reason,
        Staff $actor,
    ): void {
        DB::table('dry_weights')->updateOrInsert(
            [
                'patient_id' => $patient->id,
                'effective_from' => $effectiveFrom->toDateString(),
            ],
            [
                // DECIMAL(6,2). Never a float -- see CLAUDE.md.
                'weight_kg' => $weightKg,
                'reason' => $reason,
                'set_by' => $actor->id,
            ],
        );
    }

    /** The dry weight in force today, or null if none has been set. */
    public function currentDryWeight(Patient $patient): ?string
    {
        $row = DB::table('v_current_dry_weight')->where('patient_id', $patient->id)->first();

        return $row === null ? null : (string) $row->weight_kg;
    }

    private function recordStatus(
        Patient $patient,
        string $status,
        Carbon $effectiveOn,
        ?string $reason,
        ?string $destination,
        Staff $actor,
    ): void {
        DB::table('patient_status_histories')->insert([
            'patient_id' => $patient->id,
            'status' => $status,
            'effective_on' => $effectiveOn->toDateString(),
            'reason' => $reason,
            'destination' => $destination,
            'recorded_by' => $actor->id,
            'recorded_at' => Carbon::now(),
        ]);
    }
}
