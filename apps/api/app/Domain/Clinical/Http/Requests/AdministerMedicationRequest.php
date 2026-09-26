<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A dose given at the chair.
 *
 * Invariant 7: a high-alert drug requires a witness. That is checked in
 * FlowSheetService (which also refuses a self-witnessed dose) and enforced again
 * by the medication_administrations_bi trigger. It is not checked here, because
 * whether a drug is high-alert is a property of the drug, not of the request.
 */
final class AdministerMedicationRequest extends FormRequest
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
            'medication_id' => ['required', 'integer', 'exists:medication_refs,id'],
            'order_id' => ['nullable', 'integer', 'exists:medication_orders,id'],
            'dose' => ['required', 'numeric', 'min:0'],
            'dose_unit' => ['required', 'string', 'max:20'],
            'route' => ['required', 'string', 'max:20'],
            'administered_at' => ['nullable', 'date'],
            'timing' => ['nullable', Rule::in(['pre', 'intra', 'post'])],
            'lot_id' => ['nullable', 'integer', 'exists:stock_lots,id'],
            'site' => ['nullable', 'string', 'max:60'],
            'witnessed_by' => ['nullable', 'integer', 'exists:staff,id'],
            'not_given' => ['nullable', 'boolean'],
            'not_given_reason' => ['nullable', 'required_if:not_given,true', 'string', 'max:255'],
            'reaction' => ['nullable', 'string', 'max:255'],
        ];
    }
}
