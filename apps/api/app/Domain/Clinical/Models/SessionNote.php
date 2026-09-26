<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only. A correction never edits an earlier note; it creates a new one
 * pointing at the original through amends_id.
 */
class SessionNote extends Model
{
    protected $table = 'session_notes';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = ['created_at' => 'datetime'];

    /** @return BelongsTo<self, $this> */
    public function amends(): BelongsTo
    {
        return $this->belongsTo(self::class, 'amends_id');
    }
}
