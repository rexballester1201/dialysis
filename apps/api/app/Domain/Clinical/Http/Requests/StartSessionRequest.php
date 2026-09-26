<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The settings the treatment is actually run at, as opposed to the prescribed ones. */
final class StartSessionRequest extends FormRequest
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
            // Invariant 1 refuses a cohort-breaching chair or machine. A reason
            // overrides it and is written onto the chart -- so it has to be a
            // reason someone can still make sense of a year later.
            'cohort_override_reason' => ['nullable', 'string', 'min:10', 'max:255'],
            'started_at' => ['nullable', 'date'],
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
            'dialyzer_item_id' => ['nullable', 'integer', 'exists:items,id'],
            'dialyzer_unit_id' => ['nullable', 'integer', 'exists:dialyzer_units,id'],
            // The label on the unit, which is what a nurse actually scans. The
            // BIGINT id never leaves the server (the dialyzer list does not
            // expose it), so this is the only way a client can name one --
            // label_code is UNIQUE, the same role claim_no plays for claims.
            // Resolved across ALL patients on purpose: another patient's
            // dialyzer must be refused by invariant 8 by name, not reported as
            // "not found".
            'dialyzer_label_code' => ['nullable', 'string', 'max:40', 'exists:dialyzer_units,label_code'],
            'dialyzer_use_no' => ['nullable', 'integer', 'min:1'],
            'bloodline_lot_id' => ['nullable', 'integer', 'exists:stock_lots,id'],
            'vascular_access_id' => ['nullable', 'integer', 'exists:vascular_accesses,id'],
            'needle_gauge' => ['nullable', 'integer', 'between:13,20'],
            'cannulation_attempts' => ['nullable', 'integer', 'between:0,10'],
            // Matches hd_rx_duration_ck on the prescription side.
            'planned_duration_min' => ['nullable', 'integer', 'between:30,600'],
            'planned_uf_ml' => ['nullable', 'integer', 'between:0,10000'],
            // Matches hd_rx_qb_ck.
            'blood_flow_set_ml_min' => ['nullable', 'integer', 'between:50,600'],
            'dialysate_flow_ml_min' => ['nullable', 'integer', 'between:100,1000'],
            'dialysate_na_mmol' => ['nullable', 'numeric', 'between:120,160'],
            'dialysate_k_mmol' => ['nullable', 'numeric', 'between:0,4'],
            'dialysate_ca_mmol' => ['nullable', 'numeric', 'between:0,2'],
            'dialysate_hco3_mmol' => ['nullable', 'numeric', 'between:20,45'],
            // Matches hd_rx_temp_ck.
            'dialysate_temp_c' => ['nullable', 'numeric', 'between:33,39'],
            'anticoagulant' => ['nullable', Rule::in(['none', 'heparin', 'lmwh', 'citrate', 'saline_flush', 'other'])],
            'ac_loading_dose' => ['nullable', 'numeric', 'min:0'],
            'ac_maintenance_hr' => ['nullable', 'numeric', 'min:0'],
            'priming_volume_ml' => ['nullable', 'integer', 'between:0,2000'],
        ];
    }
}
