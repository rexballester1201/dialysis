<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Models;

use App\Domain\Clinical\Enums\SessionStatus;
use App\Domain\Core\Models\Patient;
use App\Domain\Core\Models\Staff;
use App\Support\Auditing\Auditable;
use App\Support\Database\HasGeneratedColumns;
use Database\Factories\TreatmentSessionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TreatmentSession extends Model
{
    use Auditable;

    /** @use HasFactory<TreatmentSessionFactory> */
    use HasFactory;

    use HasGeneratedColumns;
    use HasUlids;

    protected $table = 'treatment_sessions';

    protected $guarded = ['id', 'public_id'];

    /**
     * Maintained by MySQL. Listing them here keeps Eloquent from ever trying
     * to write them, which MySQL would reject.
     */
    /** @var list<string> */
    protected array $generated = [
        'idwg_kg', 'weight_loss_kg', 'actual_duration_min',
        'slot_patient_key', 'slot_station_key',
    ];

    protected $casts = [
        'status' => SessionStatus::class,
        'session_date' => 'date',
        'pre_assessment' => 'array',
        'checked_in_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'discharged_at' => 'datetime',
        'nurse_signed_at' => 'datetime',
        'physician_signed_at' => 'datetime',
        'locked_at' => 'datetime',
        'synced_at' => 'datetime',
        'is_billable' => 'boolean',
        'is_first_ever' => 'boolean',
        'pre_weight_kg' => 'decimal:2',
        'post_weight_kg' => 'decimal:2',
        'dry_weight_kg' => 'decimal:2',
        'ktv' => 'decimal:2',
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
    protected static function newFactory(): TreatmentSessionFactory
    {
        return TreatmentSessionFactory::new();
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function isFullySigned(): bool
    {
        return $this->nurse_signed_at !== null && $this->physician_signed_at !== null;
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<HdPrescription, $this> */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(HdPrescription::class, 'prescription_id');
    }

    /** @return BelongsTo<Staff, $this> */
    public function primaryNurse(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'primary_nurse_id');
    }

    /** @return HasMany<SessionVital, $this> */
    public function vitals(): HasMany
    {
        return $this->hasMany(SessionVital::class, 'session_id')->orderBy('recorded_at');
    }

    /** @return HasMany<SessionEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(SessionEvent::class, 'session_id')->orderBy('occurred_at');
    }

    /** @return HasMany<SessionNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(SessionNote::class, 'session_id');
    }

    /** @return HasMany<MedicationAdministration, $this> */
    public function medications(): HasMany
    {
        return $this->hasMany(MedicationAdministration::class, 'session_id');
    }
}
