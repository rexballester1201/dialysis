<?php

declare(strict_types=1);

namespace App\Domain\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:160'],
            'password' => ['required', 'string'],
            // Matches sync_batches.device_id, which is the same tablet identifier
            // the outbox stamps on every batch it sends.
            'device_id' => ['required', 'string', 'max:64'],
        ];
    }
}
