<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Models;

use App\Support\Auditing\Auditable;
use Illuminate\Database\Eloquent\Model;

class MedicationAdministration extends Model
{
    use Auditable;

    protected $table = 'medication_administrations';

    protected $guarded = ['id'];

    protected $casts = [
        'administered_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public $timestamps = false;
}
