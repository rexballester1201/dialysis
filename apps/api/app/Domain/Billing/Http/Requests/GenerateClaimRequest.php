<?php

declare(strict_types=1);

namespace App\Domain\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class GenerateClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ClaimPolicy::create is applied in the controller.
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // By public_id. The BIGINT row id never leaves the server, so a
            // client has no business knowing one to send back.
            //
            // The upper bound is a sanity limit on the payload, NOT the benefit
            // cap. It used to be 156 -- this year's PhilHealth allotment, typed
            // into a validator, which is exactly the constant CLAUDE.md forbids:
            // the cap has moved before (90 -> 156). The allotment is enforced by
            // BenefitLedger from the program row.
            'session_public_ids' => ['required', 'array', 'min:1', 'max:400'],
            'session_public_ids.*' => ['required', 'string', 'ulid', 'distinct', 'exists:treatment_sessions,public_id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'session_public_ids.required' => 'Name the sessions this claim covers.',
        ];
    }
}
