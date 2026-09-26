<?php

declare(strict_types=1);

namespace App\Domain\Ops\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReceiveStockRequest extends FormRequest
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
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'lot_no' => ['required', 'string', 'max:60'],
            'qty' => ['required', 'numeric', 'gt:0'],
            // Nullable because some consumables genuinely carry no expiry, but
            // for anything sterile it is the field that decides issue order.
            'expiry_date' => ['nullable', 'date'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'received_on' => ['nullable', 'date', 'before_or_equal:today'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'invoice_no' => ['nullable', 'string', 'max:60'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
