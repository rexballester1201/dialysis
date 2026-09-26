<?php

declare(strict_types=1);

namespace App\Domain\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChangePatientStatusRequest extends FormRequest
{
    /**
     * Mirrors the patients_status_ck CHECK constraint. Kept in step with it on
     * purpose: the database is the backstop, this is the good error message.
     */
    public const STATUSES = [
        'active',
        'on_hold',
        'hospitalised',
        'transferred_out',
        'transplanted',
        'recovered_function',
        'deceased',
        'lost_to_followup',
        'discontinued',
    ];

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
            'status' => ['required', Rule::in(self::STATUSES)],
            // A status change may be backdated -- the ward often records a
            // transfer days later -- but it cannot be dated into the future.
            'effective_on' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['nullable', 'string', 'max:255'],
            // Where the patient went, for a transfer.
            'destination' => ['nullable', 'string', 'max:160'],
        ];
    }
}
