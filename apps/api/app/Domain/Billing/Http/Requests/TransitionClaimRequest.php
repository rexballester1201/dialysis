<?php

declare(strict_types=1);

namespace App\Domain\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TransitionClaimRequest extends FormRequest
{
    /**
     * Mirrors claims_status_ck.
     *
     * Every status is accepted HERE so the ledger can refuse the ones a person
     * may not pick -- approved, partially_paid and paid come from a remittance
     * -- with a sentence saying why, rather than "the selected status is invalid".
     */
    public const STATUSES = [
        'draft', 'ready', 'submitted', 'acknowledged', 'in_process', 'approved',
        'partially_paid', 'paid', 'denied', 'returned', 'resubmitted', 'void',
    ];

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
            'status' => ['required', Rule::in(self::STATUSES)],
            // A denial or a return without a reason is a claim nobody can
            // resubmit. A void frees the claim's sessions to be billed again,
            // so it carries a reason someone can still follow a year later --
            // the same ten characters every other override in the system asks.
            'remarks' => [
                'nullable',
                'required_if:status,denied,returned,void',
                'string',
                'max:255',
                Rule::when($this->input('status') === 'void', ['min:10']),
            ],
        ];
    }
}
