<?php

declare(strict_types=1);

namespace App\Domain\Ops\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One reprocessing cycle for a physical dialyzer.
 *
 * The measured total cell volume is what decides whether the unit goes round
 * again, so it is required rather than optional -- reprocessing without
 * measuring is the failure mode this record exists to prevent.
 */
final class ReprocessDialyzerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorised in the controller.
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'reprocessed_at' => ['nullable', 'date'],
            'method' => ['nullable', Rule::in(['manual', 'automated'])],
            'germicide' => ['nullable', 'string', 'max:40'],
            'germicide_conc' => ['nullable', 'string', 'max:20'],
            'tcv_ml' => ['required', 'numeric', 'between:0,1000'],
            'pressure_test_passed' => ['nullable', 'boolean'],
            'fibre_bundle_ok' => ['nullable', 'boolean'],
            'visual_ok' => ['nullable', 'boolean'],
            'residual_test_passed' => ['nullable', 'boolean'],
            'accepted' => ['required', 'boolean'],
            'reject_reason' => ['nullable', 'required_if:accepted,false', 'string', 'max:255'],
            'session_id' => ['nullable', 'integer', 'exists:treatment_sessions,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tcv_ml.required' => 'Measure the total cell volume. It is what decides whether this dialyzer may be used again.',
            'reject_reason.required_if' => 'A rejected dialyzer needs a reason; it becomes the discard record.',
        ];
    }
}
