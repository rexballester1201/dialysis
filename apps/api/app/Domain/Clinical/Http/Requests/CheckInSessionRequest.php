<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pre-dialysis observations.
 *
 * The ranges mirror the CHECK constraints where the schema has them
 * (ts_preweight_ck is 10..400 kg) and are otherwise physiological bounds that
 * catch a transposed reading -- a 148 typed into the pulse field, say -- before
 * it reaches a generated column.
 */
final class CheckInSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorised against the session in the controller.
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // Invariant 1 refuses a cohort-breaching chair or machine. A reason
            // overrides it and is written onto the chart -- so it has to be a
            // reason someone can still make sense of a year later.
            'cohort_override_reason' => ['nullable', 'string', 'min:10', 'max:255'],
            'pre_weight_kg' => ['required', 'numeric', 'between:10,400', 'decimal:0,2'],
            'pre_bp_sys' => ['nullable', 'integer', 'between:40,300'],
            'pre_bp_dia' => ['nullable', 'integer', 'between:20,200'],
            'pre_bp_sys_standing' => ['nullable', 'integer', 'between:40,300'],
            'pre_bp_dia_standing' => ['nullable', 'integer', 'between:20,200'],
            'pre_pulse' => ['nullable', 'integer', 'between:20,250'],
            'pre_temp_c' => ['nullable', 'numeric', 'between:30,45'],
            'pre_resp_rate' => ['nullable', 'integer', 'between:4,80'],
            'pre_spo2_pct' => ['nullable', 'integer', 'between:50,100'],
            'pre_glucose_mmol' => ['nullable', 'numeric', 'between:0,60'],
            'pre_assessment' => ['nullable', 'array'],
            'pre_notes' => ['nullable', 'string'],
            'vascular_access_id' => ['nullable', 'integer', 'exists:vascular_accesses,id'],
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
            'station_id' => ['nullable', 'integer', 'exists:stations,id'],
        ];
    }
}
