<?php

declare(strict_types=1);

namespace App\Domain\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RecordPaymentRequest extends FormRequest
{
    /** Mirrors pay_method_ck. */
    public const METHODS = [
        'cash', 'card', 'bank_transfer', 'ewallet', 'cheque', 'payer_remittance', 'adjustment',
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
            // pay_amount_ck forbids zero. A negative amount is a refund or a
            // correction, which is a real thing and stays allowed.
            'amount' => ['required', 'numeric', 'not_in:0', 'decimal:0,2'],
            'paid_on' => ['nullable', 'date'],
            'method' => ['required', Rule::in(self::METHODS)],
            'reference_no' => ['nullable', 'string', 'max:60'],
            'payer_id' => ['nullable', 'integer', 'exists:payers,id'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
