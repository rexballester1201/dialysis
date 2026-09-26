<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class OrderLabPanelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The patient policy and the role check run in the controller.
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // A grouping in lab_test_refs. The service refuses one with no tests
            // behind it rather than creating an order that draws blood for
            // nothing.
            'panel' => ['nullable', 'string', 'max:20'],
            'ordered_on' => ['nullable', 'date'],
            'lab_name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
