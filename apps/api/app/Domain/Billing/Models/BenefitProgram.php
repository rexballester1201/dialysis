<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The payer package in force for a modality over a date window.
 *
 * This is the money. The case rate and the session cap decide what a treatment
 * is worth and how many a patient is still entitled to, and both are rows rather
 * than constants because both have already moved: the PhilHealth case rate went
 * 2,600 -> 4,000 -> 6,350 and the annual allotment 90 -> 156.
 *
 * Superseded rows stay in the table on purpose, so a historical claim re-prices
 * against what was in force when the treatment happened.
 */
class BenefitProgram extends Model
{
    protected $table = 'benefit_programs';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        // DECIMAL(12,2). Money is never a float -- see CLAUDE.md.
        'case_rate' => 'decimal:2',
        'facility_fee' => 'decimal:2',
        'professional_fee' => 'decimal:2',
        'no_balance_billing' => 'boolean',
    ];

    /** What this program pays for a given number of sessions. */
    public function amountFor(int $sessions): string
    {
        return bcmul((string) ($this->case_rate ?? '0'), (string) $sessions, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'case_rate' => $this->case_rate,
            'currency' => $this->currency,
            'sessions_per_period' => (int) $this->sessions_per_period,
            'period_kind' => $this->period_kind,
            'no_balance_billing' => (bool) $this->no_balance_billing,
            // The circular this rate came from. Worth carrying to the screen:
            // it is what someone checks when the payer disputes an amount.
            'circular_ref' => $this->circular_ref,
        ];
    }
}
