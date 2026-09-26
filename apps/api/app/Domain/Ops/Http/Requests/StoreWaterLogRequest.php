<?php

declare(strict_types=1);

namespace App\Domain\Ops\Http\Requests;

use App\Domain\Ops\Services\WaterComplianceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The pre-dialysis water check.
 *
 * total_chlorine_ppm is required, not optional: a check that omits it is not a
 * check, and WaterComplianceService refuses to treat a missing reading as a pass.
 */
final class StoreWaterLogRequest extends FormRequest
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
            // A retired RO unit is not where this morning's water came from.
            'water_system_id' => ['required', 'integer', Rule::exists('water_systems', 'id')->where('is_active', 1)],
            // Optional: the server's clock is the default. A stated time must say
            // which zone it is in -- a bare "05:30" is ambiguous, and a naive
            // time read as UTC is how the bedside once stamped every
            // observation eight hours early (CLAUDE.md, MySQL rule 11).
            'logged_at' => ['nullable', 'date', 'regex:/(Z|[+-]\d{2}:?\d{2})$/'],
            // By code, not row id.
            'shift_code' => ['nullable', 'string', 'exists:shifts,code'],
            // DECIMAL(6,3). The action limit is 0.1 ppm; the upper bound is what
            // a test strip can physically read. Three places at most, because a
            // fourth would be rounded away on save: 0.1004 would be flagged as a
            // breach here and then read back from the column as 0.100, a pass.
            'total_chlorine_ppm' => ['required', 'numeric', 'decimal:0,3', 'between:0,10'],
            'free_chlorine_ppm' => ['nullable', 'numeric', 'decimal:0,3', 'between:0,10'],
            // The rest are held to their columns' precision and range for the same
            // reason: a value the column cannot hold is rounded, or refused by
            // MySQL as a 500, instead of being refused here with a reason.
            'feed_pressure_psi' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,200'],
            'product_pressure_psi' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,200'],
            'reject_pressure_psi' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,200'],
            'feed_conductivity_us' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,999999.99'],
            'product_conductivity_us' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,999999.99'],
            'rejection_pct' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,100'],
            'temperature_c' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,60'],
            'hardness_ppm' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,9999.99'],
            'ph' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,14'],
            'softener_salt_ok' => ['nullable', 'boolean'],
            'carbon_tank_ok' => ['nullable', 'boolean'],
            // What was done about a breach. Required when one is being reported,
            // because "we noticed and did nothing" must be a deliberate entry.
            // (This comment used to promise that while the rule said nullable.)
            'action_taken' => [
                Rule::requiredIf(fn (): bool => $this->breaches()),
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'logged_at.regex' => 'State the time with its zone (for example 2026-09-27T05:30:00+08:00), or leave it out to use now.',
            'action_taken.required' => 'This reading is above the '.WaterComplianceService::TOTAL_CHLORINE_LIMIT_PPM
                .' ppm action limit. Say what was done about it -- even if that is "stopped, carbon tank being changed".',
            'water_system_id.exists' => 'That water system is not an active one.',
        ];
    }

    /** Uses the service's own comparison, so the form and the gate cannot disagree about a breach. */
    private function breaches(): bool
    {
        $reading = $this->input('total_chlorine_ppm');

        return is_numeric($reading) && app(WaterComplianceService::class)->breaches($reading);
    }
}
