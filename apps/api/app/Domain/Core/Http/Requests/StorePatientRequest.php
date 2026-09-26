<?php

declare(strict_types=1);

namespace App\Domain\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registration.
 *
 * `birth_date` cannot be in the future -- a rule MySQL cannot hold, because
 * CHECK constraints may not call CURRENT_DATE or any other non-deterministic
 * function (CLAUDE.md, MySQL rule 4). It lives here or it lives nowhere.
 */
final class StorePatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // PatientPolicy::create is applied in the controller.
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'mrn' => ['required', 'string', 'max:30', Rule::unique('patients', 'mrn')],
            'first_name' => ['required', 'string', 'max:80'],
            'middle_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'birth_date' => ['required', 'date', 'before_or_equal:today'],
            'sex' => ['required', Rule::in(['male', 'female', 'other', 'unknown'])],
            'blood_type' => ['nullable', 'string', 'max:8'],
            'civil_status' => ['nullable', 'string', 'max:20'],
            'nationality' => ['nullable', 'string', 'max:60'],
            'religion' => ['nullable', 'string', 'max:60'],
            'occupation' => ['nullable', 'string', 'max:80'],
            'mobile' => ['nullable', 'string', 'max:40'],
            'landline' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'address_line' => ['nullable', 'string', 'max:160'],
            'barangay' => ['nullable', 'string', 'max:80'],
            'city' => ['nullable', 'string', 'max:80'],
            'province' => ['nullable', 'string', 'max:80'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            // Not in the future, and not before the patient was born.
            'first_dialysis_date' => ['nullable', 'date', 'before_or_equal:today', 'after_or_equal:birth_date'],
            'first_session_here_on' => ['nullable', 'date', 'after_or_equal:first_dialysis_date'],
            'referring_physician' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'birth_date.before_or_equal' => 'The birth date cannot be in the future.',
            'first_dialysis_date.after_or_equal' => 'The first dialysis date cannot be before the birth date.',
        ];
    }
}
