<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The only sanctioned way to change a signed record.
 *
 * The original values stay in place; the correction becomes a session note and a
 * full before/after pair in audit_logs. A reason is mandatory -- an amendment
 * without one is indistinguishable from someone quietly fixing a number.
 */
final class AmendSessionRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'changes' => ['required', 'array', 'min:1'],
            // Clinical values only. Identity, ownership and the lock itself are
            // rejected by SessionLockService as well; naming them here produces a
            // field error instead of an exception.
            'changes.ktv' => ['sometimes', 'numeric', 'between:0,5'],
            'changes.urr_pct' => ['sometimes', 'numeric', 'between:0,100'],
            'changes.pre_bun_mmol' => ['sometimes', 'numeric', 'min:0'],
            'changes.post_bun_mmol' => ['sometimes', 'numeric', 'min:0'],
            'changes.net_uf_ml' => ['sometimes', 'integer', 'between:0,10000'],
            'changes.post_weight_kg' => ['sometimes', 'numeric', 'between:10,400'],
            'changes.discharge_condition' => ['sometimes', 'string', 'max:16'],
            'changes.discharge_notes' => ['sometimes', 'string'],
            'changes.termination_notes' => ['sometimes', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'An amendment requires a reason; it becomes part of the record.',
            'reason.min' => 'Give a reason that will still mean something to someone reading this in a year.',
        ];
    }
}
