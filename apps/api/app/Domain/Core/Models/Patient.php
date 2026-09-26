<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use App\Domain\Clinical\Enums\Cohort;
use App\Domain\Clinical\Models\HdPrescription;
use App\Domain\Clinical\Models\TreatmentSession;
use App\Support\Auditing\Auditable;
use App\Support\Database\HasGeneratedColumns;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Patient extends Model
{
    use Auditable;

    /** @use HasFactory<PatientFactory> */
    use HasFactory;

    use HasGeneratedColumns;
    use HasUlids;    // populates public_id, NOT the primary key
    use SoftDeletes; // a patient record is never hard-deleted

    protected $table = 'patients';

    protected $guarded = ['id', 'public_id', 'full_name'];

    /**
     * Maintained by MySQL. $guarded stops mass assignment; this stops Eloquent
     * writing the column at all, which MySQL rejects outright.
     *
     * @var list<string>
     */
    protected array $generated = ['full_name'];

    protected $casts = [
        'birth_date' => 'date',
        'first_dialysis_date' => 'date',
        'first_session_here_on' => 'date',
        'status_changed_on' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** Route model binding and API payloads expose the ULID, never the row id. */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * Named explicitly: factory auto-discovery expects Database\Factories to
     * mirror App\Models, and this codebase groups models by domain module.
     */
    protected static function newFactory(): PatientFactory
    {
        return PatientFactory::new();
    }

    /** @return HasMany<TreatmentSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(TreatmentSession::class);
    }

    /** @return HasMany<HdPrescription, $this> */
    public function prescriptions(): HasMany
    {
        return $this->hasMany(HdPrescription::class);
    }

    /** The prescription in force on a given date, or null. */
    public function prescriptionOn(\DateTimeInterface $date): ?HdPrescription
    {
        return $this->prescriptions()
            ->where('effective_from', '<=', $date)
            ->where('effective_to_x', '>', $date)
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * Derived from the latest result per serology marker. Read through the
     * v_patient_cohort view so PHP and SQL can never disagree about it.
     */
    public function cohort(): Cohort
    {
        $row = DB::table('v_patient_cohort')->where('patient_id', $this->id)->first();

        return Cohort::from($row->cohort ?? 'clean');
    }
}
