<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use App\Support\Database\HasGeneratedColumns;
use Database\Factories\StaffFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class Staff extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<StaffFactory> */
    use HasFactory;

    use HasGeneratedColumns;
    use HasUlids;
    use Notifiable;

    protected $table = 'staff';

    protected $guarded = ['id', 'public_id', 'full_name'];

    /**
     * Maintained by MySQL. $guarded stops mass assignment; this stops Eloquent
     * writing the column at all, which MySQL rejects outright.
     *
     * @var list<string>
     */
    protected array $generated = ['full_name'];

    protected $hidden = ['password', 'clinical_pin_hash', 'two_factor_secret', 'remember_token'];

    protected $casts = [
        'licence_expires_on' => 'date',
        'last_login_at' => 'datetime',
        'locked_until' => 'datetime',
        'is_active' => 'boolean',
        'password' => 'hashed',
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** Named explicitly; models are grouped by domain module, not under App\Models. */
    protected static function newFactory(): StaffFactory
    {
        return StaffFactory::new();
    }

    public function hasRole(string $code): bool
    {
        return DB::table('role_staff')
            ->where('staff_id', $this->id)
            ->where('role_code', $code)
            ->exists();
    }
}
