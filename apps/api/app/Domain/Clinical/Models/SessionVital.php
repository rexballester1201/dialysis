<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Models;

use App\Support\Database\HasGeneratedColumns;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of the flow sheet.
 *
 * Append-only by design and keyed by (session_id, recorded_at). There is
 * deliberately no update path: that key is what makes these conflict-free
 * between a tablet that has been offline and a desk that has not.
 */
class SessionVital extends Model
{
    use HasGeneratedColumns;

    protected $table = 'session_vitals';

    protected $guarded = ['id'];

    /**
     * Mean arterial pressure, computed by MySQL as dia + (sys - dia)/3.
     *
     * Listed here for both halves of the rule: Eloquent must never write it, and
     * it has to be read back after an insert or the API returns null for the row
     * it just created.
     *
     * @var list<string>
     */
    protected array $generated = ['map_mmhg'];

    protected $casts = [
        'recorded_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public $timestamps = false;
}
