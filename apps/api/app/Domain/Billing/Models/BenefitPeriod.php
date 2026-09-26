<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One patient's entitlement window under a program.
 *
 * `sessions_allotted` is copied from the program when the period opens, so a cap
 * change mid-year does not retroactively rewrite what a patient was already
 * entitled to.
 */
class BenefitPeriod extends Model
{
    protected $table = 'benefit_periods';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'created_at' => 'datetime',
    ];
}
