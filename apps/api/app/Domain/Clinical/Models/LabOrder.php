<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Models;

use App\Support\Auditing\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A request for a panel of tests.
 *
 * Ordering is a clinical act -- it commits the unit to drawing blood -- so it is
 * audited from the first write. The order is deliberately optional for a result:
 * a patient arriving with a panel from an outside laboratory has a result and no
 * order, and refusing to file it would push that result onto paper.
 */
class LabOrder extends Model
{
    use Auditable;

    protected $table = 'lab_orders';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'ordered_on' => 'date',
        'collected_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /** @return HasMany<LabResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(LabResult::class, 'order_id');
    }
}
