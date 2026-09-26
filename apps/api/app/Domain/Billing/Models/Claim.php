<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Support\Auditing\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Audited from the first write: a claim is money, and a changed amount or
 * status needs to be reconstructable. No controller in Phase 0.
 */
class Claim extends Model
{
    use Auditable;

    protected $table = 'claims';

    protected $guarded = ['id'];

    /**
     * Routed by claim number, not by row id.
     *
     * The repo rule is a ULID `public_id` in every URL, but the baseline gives
     * claims no such column -- it gives them `claim_no`, which is unique, is what
     * the payer's own portal quotes back, and keeps the BIGINT id off the wire
     * just as effectively.
     */
    public function getRouteKeyName(): string
    {
        return 'claim_no';
    }

    public const UPDATED_AT = 'updated_at';

    public const CREATED_AT = 'created_at';

    protected $casts = [
        'service_from' => 'date',
        'service_to' => 'date',
        'submitted_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'paid_at' => 'datetime',
        // Money is DECIMAL(12,2) in MySQL and stays a string in PHP.
        // Never float -- see CLAUDE.md.
        'amount_claimed' => 'decimal:2',
        'amount_approved' => 'decimal:2',
        'amount_paid' => 'decimal:2',
    ];
}
