<?php

declare(strict_types=1);

namespace App\Domain\Ops\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RecordDisinfectionRequest extends FormRequest
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
            'performed_at' => ['nullable', 'date'],
            'method' => ['required', Rule::in(['heat', 'chemical', 'heat_citric', 'peracetic', 'other'])],
            'agent' => ['nullable', 'string', 'max:60'],
            'duration_min' => ['nullable', 'integer', 'between:1,600'],
            'residual_test_done' => ['nullable', 'boolean'],
            // A positive residual means germicide is still in the circuit. It is
            // recorded as found; acting on it is the technician's job.
            'residual_test_result' => ['nullable', Rule::in(['negative', 'positive', 'not_done'])],
        ];
    }
}
