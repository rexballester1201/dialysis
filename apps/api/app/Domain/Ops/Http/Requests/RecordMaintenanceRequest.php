<?php

declare(strict_types=1);

namespace App\Domain\Ops\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RecordMaintenanceRequest extends FormRequest
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
            'maintenance_type' => ['required', Rule::in([
                'preventive', 'corrective', 'calibration', 'safety_test', 'decommission',
            ])],
            'performed_on' => ['nullable', 'date', 'before_or_equal:today'],
            'run_hours_at' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'parts_replaced' => ['nullable', 'string'],
            // Often an external engineer rather than a staff member.
            'performed_by' => ['nullable', 'string', 'max:120'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'passed' => ['nullable', 'boolean'],
            'next_due_on' => ['nullable', 'date', 'after:today'],
            'document_path' => ['nullable', 'string', 'max:255'],
        ];
    }
}
