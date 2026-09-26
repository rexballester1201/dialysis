<?php

declare(strict_types=1);

namespace App\Domain\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Demographic correction.
 *
 * `status` is deliberately absent: moving a patient between statuses goes
 * through PatientService::changeStatus() so the history row cannot be skipped.
 * Accepting it here would make that bypassable from a form.
 */
final class UpdatePatientRequest extends FormRequest
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
        $patient = $this->route('patient');
        $patientId = is_object($patient) && property_exists($patient, 'id') ? $patient->id : null;

        return [
            'mrn' => ['sometimes', 'string', 'max:30', Rule::unique('patients', 'mrn')->ignore($patientId)],
            'first_name' => ['sometimes', 'string', 'max:80'],
            'middle_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['sometimes', 'string', 'max:80'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'birth_date' => ['sometimes', 'date', 'before_or_equal:today'],
            'sex' => ['sometimes', Rule::in(['male', 'female', 'other', 'unknown'])],
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
            'first_dialysis_date' => ['nullable', 'date', 'before_or_equal:today'],
            'first_session_here_on' => ['nullable', 'date'],
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
        ];
    }
}
