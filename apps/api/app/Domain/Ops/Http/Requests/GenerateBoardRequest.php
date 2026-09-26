<?php

declare(strict_types=1);

namespace App\Domain\Ops\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class GenerateBoardRequest extends FormRequest
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
            'date' => ['required', 'date'],
        ];
    }
}
