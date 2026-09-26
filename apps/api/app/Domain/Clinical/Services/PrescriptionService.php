<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Models\HdPrescription;
use App\Domain\Core\Models\Patient;
use App\Domain\Core\Models\Staff;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Prescriptions are versioned, never edited in place.
 *
 * MySQL has no exclusion constraint, so non-overlap is guaranteed by three
 * things together: a row lock taken here, the closing UPDATE below, and the
 * hd_prescriptions_bi/bu triggers as the backstop.
 */
final class PrescriptionService
{
    /** @param  array<string, mixed>  $attributes */
    public function revise(
        Patient $patient,
        array $attributes,
        CarbonInterface $effectiveFrom,
        Staff $prescriber,
        string $reason,
    ): HdPrescription {
        return DB::transaction(function () use ($patient, $attributes, $effectiveFrom, $prescriber, $reason) {
            // Lock this patient's prescription rows for the duration of the
            // transaction so two physicians cannot both open a new version.
            $current = HdPrescription::query()
                ->where('patient_id', $patient->id)
                ->where('effective_from', '<=', $effectiveFrom)
                ->where('effective_to_x', '>', $effectiveFrom)
                ->lockForUpdate()
                ->orderByDesc('effective_from')
                ->first();

            $nextVersion = (int) HdPrescription::query()
                ->where('patient_id', $patient->id)
                ->lockForUpdate()
                ->max('version') + 1;

            if ($current !== null) {
                if ($current->effective_from->equalTo($effectiveFrom)) {
                    // Same-day revision: supersede rather than leave a zero-length row.
                    $current->delete();
                } else {
                    $current->forceFill(['effective_to' => $effectiveFrom])->save();
                }
            }

            return HdPrescription::create($attributes + [
                'patient_id' => $patient->id,
                'version' => $nextVersion,
                'effective_from' => $effectiveFrom->toDateString(),
                'effective_to' => null,
                'prescribed_by' => $prescriber->id,
                'change_reason' => $reason,
                'created_by' => $prescriber->id,
            ]);
        });
    }

    public function discontinue(Patient $patient, CarbonInterface $on): void
    {
        DB::transaction(function () use ($patient, $on) {
            HdPrescription::query()
                ->where('patient_id', $patient->id)
                ->whereNull('effective_to')
                ->lockForUpdate()
                ->update(['effective_to' => $on->toDateString()]);
        });
    }
}
