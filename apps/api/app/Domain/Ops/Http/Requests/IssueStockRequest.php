<?php

declare(strict_types=1);

namespace App\Domain\Ops\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class IssueStockRequest extends FormRequest
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
            'qty' => ['required', 'numeric', 'gt:0'],
            'session_id' => ['required', 'integer', 'exists:treatment_sessions,id'],
            'patient_id' => ['nullable', 'integer', 'exists:patients,id'],
        ];
    }
}
