<?php

declare(strict_types=1);

namespace App\Domain\Ops\Models;

use App\Support\Auditing\Auditable;
use App\Support\Database\HasGeneratedColumns;
use Illuminate\Database\Eloquent\Model;

/**
 * One physical dialyzer, tracked by barcode across its reuse life.
 *
 * Reprocessing a patient's own dialyzer is legal and common in South-East Asia.
 * The unit belongs to one patient -- `patient_id` is NOT NULL -- and issuing it
 * to anyone else is an infection-control failure, not a paperwork slip.
 */
class DialyzerUnit extends Model
{
    use Auditable;
    use HasGeneratedColumns;

    protected $table = 'dialyzer_units';

    protected $guarded = ['id'];

    /**
     * current_tcv_ml / initial_tcv_ml as a percentage, computed by MySQL.
     *
     * @var list<string>
     */
    protected array $generated = ['tcv_pct'];

    protected $casts = [
        'first_used_on' => 'date',
        'discarded_on' => 'date',
        'initial_tcv_ml' => 'decimal:1',
        'current_tcv_ml' => 'decimal:1',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
