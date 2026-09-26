<?php

declare(strict_types=1);

namespace App\Domain\Ops\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A pre-dialysis water check.
 *
 * The one that stops treatment is total chlorine: above 0.1 ppm the carbon beds
 * are exhausted and the water reaching a dialyzer membrane can haemolyse a
 * patient. Everything else here is trend data.
 */
class WaterDailyLog extends Model
{
    protected $table = 'water_daily_logs';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'logged_at' => 'datetime',
        'created_at' => 'datetime',
        'total_chlorine_ppm' => 'decimal:3',
        'free_chlorine_ppm' => 'decimal:3',
        'is_out_of_range' => 'boolean',
        'softener_salt_ok' => 'boolean',
        'carbon_tank_ok' => 'boolean',
    ];
}
