<?php

declare(strict_types=1);

namespace App\Domain\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Dry weight is the target post-dialysis weight, and it is what IDWG and the
 * UF goal are measured against. A wrong one removes the wrong amount of fluid.
 */
final class SetDryWeightRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // PatientPolicy::update is applied in the controller.
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // Matches the dw_range_ck CHECK constraint on dry_weights.
            'weight_kg' => ['required', 'numeric', 'between:10,400', 'decimal:0,2'],
            'effective_from' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
