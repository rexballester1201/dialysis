<?php

declare(strict_types=1);

namespace App\Domain\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What the payer's remittance advice says.
 *
 * The claim's status is derived from these figures rather than supplied
 * alongside them, so there is deliberately no `status` field here: a claim
 * marked paid whose remittance says otherwise is exactly the disagreement this
 * endpoint exists to prevent.
 */
final class RecordRemittanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ClaimPolicy::transition is applied in the controller.
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // Zero is a denial, not an omission, which is why it is required.
            'amount_approved' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'amount_paid' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'denial_code' => ['nullable', 'string', 'max:40'],
            'denial_reason' => ['nullable', 'string', 'max:255'],
            // The payer's own reference, for reconciling against their portal.
            'external_ref' => ['nullable', 'string', 'max:80'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount_approved.required' => 'Record what the payer approved, even if it was nothing.',
        ];
    }
}
