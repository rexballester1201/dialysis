<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An intra-dialytic event: a hypotensive episode, cramps, a clotted circuit.
 *
 * event_code is a foreign key into event_refs, so `exists` here turns what would
 * otherwise be a constraint violation into a field error naming the bad code.
 */
final class AppendEventRequest extends FormRequest
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
            'event_code' => ['required', 'string', 'max:30', 'exists:event_refs,code'],
            'occurred_at' => ['required', 'date'],
            'severity' => ['required', Rule::in(['minor', 'moderate', 'severe', 'life_threatening'])],
            'description' => ['nullable', 'string'],
            'intervention' => ['nullable', 'string'],
            'outcome' => ['nullable', 'string', 'max:255'],
        ];
    }
}
