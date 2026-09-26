<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new prescription version.
 *
 * Prescriptions are versioned, never edited in place: a session run last month
 * must stay interpretable against what was ordered last month. The ranges mirror
 * the hd_rx_* CHECK constraints so the nurse gets a field error rather than a
 * constraint violation.
 */
final class StorePrescriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // PatientPolicy::prescribe is applied in the controller.
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'effective_from' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'modality' => ['required', Rule::in(['hd', 'hdf', 'hf', 'sled', 'ihd_acute', 'pd_capd', 'pd_apd'])],
            'duration_min' => ['required', 'integer', 'between:30,600'],
            'sessions_per_week' => ['required', 'numeric', 'between:0.5,7'],
            'blood_flow_ml_min' => ['nullable', 'integer', 'between:50,600'],
            'dialysate_flow_ml_min' => ['nullable', 'integer', 'between:100,1000'],
            'dialysate_temp_c' => ['nullable', 'numeric', 'between:33,39'],
            'dialysate_na_mmol' => ['nullable', 'numeric', 'between:120,160'],
            'dialysate_k_mmol' => ['nullable', 'numeric', 'between:0,4'],
            'dialysate_ca_mmol' => ['nullable', 'numeric', 'between:0,2'],
            'anticoagulant' => ['required', Rule::in(['none', 'heparin', 'lmwh', 'citrate', 'saline_flush', 'other'])],
            'ac_loading_dose' => ['nullable', 'numeric', 'min:0'],
            'ac_maintenance_hr' => ['nullable', 'numeric', 'min:0'],
            'dialyzer_item_id' => ['nullable', 'integer', 'exists:items,id'],
            'reuse_allowed' => ['nullable', 'boolean'],
            'max_reuse_count' => ['nullable', 'integer', 'between:1,20'],
            'vascular_access_id' => ['nullable', 'integer', 'exists:vascular_accesses,id'],
            'substitution_mode' => ['nullable', Rule::in(['pre', 'post', 'mixed'])],
            'max_uf_rate_ml_hr' => ['nullable', 'integer', 'between:0,4000'],
        ];
    }
}
