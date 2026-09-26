<?php

declare(strict_types=1);

namespace App\Domain\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DraftInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // InvoicePolicy::create is applied in the controller.
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // By public_id; the row id never leaves the server.
            'session_public_ids' => ['required', 'array', 'min:1', 'max:200'],
            'session_public_ids.*' => ['required', 'string', 'ulid', 'distinct', 'exists:treatment_sessions,public_id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'session_public_ids.required' => 'Name the sessions this invoice covers.',
        ];
    }
}
