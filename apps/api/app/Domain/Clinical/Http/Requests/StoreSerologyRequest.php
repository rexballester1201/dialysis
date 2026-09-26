<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreSerologyRequest extends FormRequest
{
    /** Mirrors serology_marker_ck. */
    public const MARKERS = ['hbsag', 'anti_hbs', 'anti_hbc', 'anti_hcv', 'hcv_rna', 'hiv', 'vdrl', 'hbv_dna'];

    /** Mirrors serology_result_ck. */
    public const RESULTS = ['reactive', 'non_reactive', 'indeterminate', 'pending'];

    public function authorize(): bool
    {
        return true; // Authorised against the patient in the controller.
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'marker' => ['required', Rule::in(self::MARKERS)],
            'result' => ['required', Rule::in(self::RESULTS)],
            'titre' => ['nullable', 'numeric', 'min:0'],
            // A specimen cannot be drawn in the future. CHECK cannot express
            // this in MySQL, so it is enforced here.
            'specimen_date' => ['required', 'date', 'before_or_equal:today'],
            'resulted_on' => ['nullable', 'date', 'after_or_equal:specimen_date'],
            'lab_name' => ['nullable', 'string', 'max:120'],
            'document_path' => ['nullable', 'string', 'max:255'],
        ];
    }
}
