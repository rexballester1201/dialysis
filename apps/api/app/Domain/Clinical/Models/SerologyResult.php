<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Models;

use App\Support\Auditing\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * A serology result decides which chair and which machine a patient may use,
 * so every change to one is audited. There is no controller for it in Phase 0;
 * the model exists so the audit trail is attached from the first write.
 */
class SerologyResult extends Model
{
    use Auditable;

    protected $table = 'serology_results';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'specimen_date' => 'date',
        'resulted_on' => 'date',
        'recorded_at' => 'datetime',
    ];
}
