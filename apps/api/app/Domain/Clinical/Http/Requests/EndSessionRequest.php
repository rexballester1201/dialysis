<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Coming off the machine.
 *
 * A treatment stopped early carries a termination_reason other than
 * `completed_as_prescribed`, and the service records it as `aborted` rather
 * than `completed` -- a short run must not flatter the monthly adequacy numbers.
 */
final class EndSessionRequest extends FormRequest
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
            'ended_at' => ['nullable', 'date'],
            'post_weight_kg' => ['required', 'numeric', 'between:10,400', 'decimal:0,2'],
            'net_uf_ml' => ['nullable', 'integer', 'between:0,10000'],
            'total_intake_ml' => ['nullable', 'integer', 'between:0,5000'],
            'post_bp_sys' => ['nullable', 'integer', 'between:40,300'],
            'post_bp_dia' => ['nullable', 'integer', 'between:20,200'],
            'post_bp_sys_standing' => ['nullable', 'integer', 'between:40,300'],
            'post_bp_dia_standing' => ['nullable', 'integer', 'between:20,200'],
            'post_pulse' => ['nullable', 'integer', 'between:20,250'],
            'post_temp_c' => ['nullable', 'numeric', 'between:30,45'],
            'post_resp_rate' => ['nullable', 'integer', 'between:4,80'],
            'post_spo2_pct' => ['nullable', 'integer', 'between:50,100'],
            'blood_volume_processed_l' => ['nullable', 'numeric', 'between:0,300'],
            // Adequacy. Kt/V target >= 1.2, URR >= 65% (KDOQI 2015) -- the bounds
            // here are what is physically recordable, not the target.
            'ktv' => ['nullable', 'numeric', 'between:0,5'],
            'ktv_method' => ['nullable', Rule::in(['single_pool_daugirdas', 'online_clearance', 'ionic', 'equilibrated'])],
            'urr_pct' => ['nullable', 'numeric', 'between:0,100'],
            'pre_bun_mmol' => ['nullable', 'numeric', 'min:0'],
            'post_bun_mmol' => ['nullable', 'numeric', 'min:0'],
            'termination_reason' => ['nullable', Rule::in([
                'completed_as_prescribed', 'patient_request', 'hypotension', 'clotting',
                'machine_fault', 'access_failure', 'medical_emergency', 'power_failure',
                'transferred_to_hospital', 'other',
            ])],
            'termination_notes' => ['nullable', 'string'],
            'ambulation' => ['nullable', Rule::in(['unassisted', 'assisted', 'wheelchair', 'stretcher'])],
            'discharge_condition' => ['nullable', Rule::in(['stable', 'improved', 'unstable', 'referred', 'expired'])],
            'discharge_notes' => ['nullable', 'string'],
            'discharged_at' => ['nullable', 'date'],
            'ac_total_given' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
