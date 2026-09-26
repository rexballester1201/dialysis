<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Models;

use App\Domain\Core\Models\Patient;
use App\Support\Auditing\Auditable;
use App\Support\Database\HasGeneratedColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;

class HdPrescription extends Model
{
    use Auditable;
    use HasGeneratedColumns;

    protected $table = 'hd_prescriptions';

    protected $guarded = ['id', 'effective_to_x'];

    /** Maintained by MySQL: COALESCE(effective_to, '9999-12-31'). */
    /** @var list<string> */
    protected array $generated = ['effective_to_x'];

    public $timestamps = false; // created_at only; a prescription is never updated in place

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'effective_to_x' => 'date',
        'created_at' => 'datetime',
        'reuse_allowed' => 'boolean',
        'sessions_per_week' => 'decimal:1',
        'target_ktv' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $rx): void {
            $rx->setRawAttributes(
                Arr::except($rx->getAttributes(), $rx->generated),
                sync: false,
            );
        });
    }

    public function isOpenEnded(): bool
    {
        return $this->effective_to === null;
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
