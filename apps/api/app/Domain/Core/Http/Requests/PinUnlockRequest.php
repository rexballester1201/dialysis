<?php

declare(strict_types=1);

namespace App\Domain\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PinUnlockRequest extends FormRequest
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
            'staff_public_id' => ['required', 'string', 'size:26'],
            // Exactly six digits. `digits:6` also rejects a PIN sent as an
            // integer with a leading zero stripped, which is the failure a
            // JSON client hits first.
            'pin' => ['required', 'string', 'digits:6'],
            'device_id' => ['required', 'string', 'max:64'],
        ];
    }
}
