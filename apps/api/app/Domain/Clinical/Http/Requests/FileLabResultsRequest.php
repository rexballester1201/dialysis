<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A filed panel.
 *
 * Structural validation only. Whether a test code exists, whether a value is
 * present, and whether the same result is already filed are all adjudicated per
 * row by LabService so that one bad row does not reject the eleven good ones
 * typed off the same page.
 *
 * There is deliberately no `abnormal_flag` here. The flag is derived from the
 * reference interval at the moment of filing; accepting one would let a client
 * store a value and a flag that contradict each other.
 */
final class FileLabResultsRequest extends FormRequest
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
            'order_id' => ['nullable', 'integer', 'exists:lab_orders,id'],
            'results' => ['required', 'array', 'min:1', 'max:100'],
            'results.*.test_code' => ['required', 'string', 'max:16'],
            'results.*.value_num' => ['nullable', 'numeric'],
            'results.*.value_text' => ['nullable', 'string', 'max:255'],
            'results.*.unit' => ['nullable', 'string', 'max:20'],
            'results.*.specimen_date' => ['required', 'date'],
            'results.*.timing' => ['nullable', 'string', 'in:pre_hd,post_hd,mid_week,non_hd_day'],
            'results.*.resulted_at' => ['nullable', 'date'],
            'results.*.source' => ['nullable', 'string', 'in:manual,hl7,csv_import,api'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'results.*.specimen_date.required' => 'Every result needs the date the specimen was taken, not the date it was typed.',
        ];
    }
}
