<?php

declare(strict_types=1);

namespace App\Domain\Ops\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A standing pattern: Mon/Wed/Fri AM in chair S-04.
 *
 * weekday_mask is a 7-bit set with bit 0 = Monday (the schema replaced a
 * Postgres text[] with a mask). 21 = Mon/Wed/Fri, 42 = Tue/Thu/Sat.
 */
final class SetStandingScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorised against the patient in the controller.
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            // Nullable: a patient can hold a shift without a fixed chair, and the
            // charge nurse assigns one on the day.
            'station_id' => ['nullable', 'integer', 'exists:stations,id'],
            // Matches ss_mask_ck. 0 would mean "no days", which is a deletion.
            'weekday_mask' => ['required', 'integer', 'between:1,127'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
