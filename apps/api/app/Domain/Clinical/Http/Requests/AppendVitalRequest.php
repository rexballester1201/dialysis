<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One row of the flow sheet.
 *
 * Append-only: there is no update counterpart, and there must not be. Vitals are
 * keyed by (session_id, recorded_at), which is what makes them conflict-free
 * across a tablet that has been offline and a desk that has not.
 */
final class AppendVitalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'recorded_at' => ['required', 'date'],
            'minutes_elapsed' => ['nullable', 'integer', 'between:0,720'],
            'bp_sys' => ['nullable', 'integer', 'between:40,300'],
            'bp_dia' => ['nullable', 'integer', 'between:20,200'],
            'pulse' => ['nullable', 'integer', 'between:20,250'],
            'temp_c' => ['nullable', 'numeric', 'between:30,45'],
            'resp_rate' => ['nullable', 'integer', 'between:4,80'],
            'spo2_pct' => ['nullable', 'integer', 'between:50,100'],
            'blood_flow_ml_min' => ['nullable', 'integer', 'between:0,600'],
            'arterial_pressure_mmhg' => ['nullable', 'integer', 'between:-400,400'],
            'venous_pressure_mmhg' => ['nullable', 'integer', 'between:-400,400'],
            'tmp_mmhg' => ['nullable', 'integer', 'between:-400,600'],
            'dialysate_flow_ml_min' => ['nullable', 'integer', 'between:0,1000'],
            'conductivity_ms_cm' => ['nullable', 'numeric', 'between:0,20'],
            'dialysate_temp_c' => ['nullable', 'numeric', 'between:33,39'],
            'uf_rate_ml_hr' => ['nullable', 'integer', 'between:0,4000'],
            'uf_volume_ml' => ['nullable', 'integer', 'between:0,10000'],
            'rbv_pct' => ['nullable', 'numeric', 'between:0,100'],
            'heparin_given' => ['nullable', 'numeric', 'min:0'],
            'comment' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', Rule::in(['manual', 'machine', 'device_import'])],
        ];
    }
}
