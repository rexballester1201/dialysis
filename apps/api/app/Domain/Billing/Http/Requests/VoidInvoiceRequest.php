<?php

declare(strict_types=1);

namespace App\Domain\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class VoidInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // InvoicePolicy::update is applied in the controller.
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // Voiding frees the invoice's sessions to be billed again. The
            // reason is written onto the invoice and into audit_logs, and ten
            // characters is the floor every other override in the system uses.
            'reason' => ['required', 'string', 'min:10', 'max:255'],
        ];
    }
}
