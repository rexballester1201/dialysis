<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Models;

use App\Support\Auditing\Auditable;
use App\Support\Database\HasGeneratedColumns;
use Illuminate\Database\Eloquent\Model;

/**
 * One filed result.
 *
 * `timing_key` is maintained by MySQL as COALESCE(timing, 'unspecified') and
 * carries the unique key that makes a result idempotent per patient, test,
 * specimen date and timing. The baseline's own key used `timing` directly, which
 * MySQL ignores when it is NULL -- so the same haemoglobin could be filed twice.
 * See the 2026_08_21 migration.
 *
 * Declared in $generated for both halves of CLAUDE.md MySQL rule 2: MySQL
 * rejects a write to it, and it does not exist on the model until read back.
 */
class LabResult extends Model
{
    use Auditable;
    use HasGeneratedColumns;

    protected $table = 'lab_results';

    protected $guarded = ['id', 'timing_key'];

    public $timestamps = false;

    /** @var list<string> */
    protected array $generated = ['timing_key'];

    protected $casts = [
        'specimen_date' => 'date',
        'resulted_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
