<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Models;

use Illuminate\Database\Eloquent\Model;

class SessionEvent extends Model
{
    protected $table = 'session_events';

    protected $guarded = ['id'];

    protected $casts = [
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public $timestamps = false;
}
