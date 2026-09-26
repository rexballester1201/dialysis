<?php

declare(strict_types=1);

namespace App\Domain\Ops\Services;

use App\Domain\Clinical\Models\TreatmentSession;
use App\Domain\Core\Models\Patient;
use App\Domain\Core\Models\Staff;
use App\Support\Exceptions\DomainRuleException;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Standing patterns, exceptions, and turning them into a day's board.
 *
 * A patient holds a standing pattern -- Mon/Wed/Fri AM, chair S-04 -- and the
 * board for a given date is that pattern plus whatever the ward changed by hand.
 *
 * Invariant 5 (one chair holds one patient per shift per day) is enforced in two
 * layers on purpose: this service reports the clash with the names in it, and
 * `ts_slot_station_uq` stops it regardless of which code path tried.
 */
final class SchedulingService
{
    /** weekday_mask bit 0 = Monday, matching the schema comment. */
    public const MONDAY_BIT = 0;

    /**
     * Open a standing pattern, closing any existing one first.
     *
     * standing_schedules_bi rejects an overlap outright, so the previous pattern
     * has to be closed in the same transaction -- the same shape as a
     * prescription revision, and for the same reason.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function setStandingSchedule(
        Patient $patient,
        array $attributes,
        CarbonInterface $effectiveFrom,
        Staff $actor,
    ): object {
        return DB::transaction(function () use ($patient, $attributes, $effectiveFrom, $actor): object {
            $current = DB::table('standing_schedules')
                ->where('patient_id', $patient->id)
                ->where('effective_from', '<=', $effectiveFrom->toDateString())
                ->where('effective_to_x', '>', $effectiveFrom->toDateString())
                ->lockForUpdate()
                ->orderByDesc('effective_from')
                ->first();

            if ($current !== null) {
                if ($current->effective_from === $effectiveFrom->toDateString()) {
                    // Same-day change: replace rather than leave a zero-length row.
                    DB::table('standing_schedules')->where('id', $current->id)->delete();
                } else {
                    DB::table('standing_schedules')
                        ->where('id', $current->id)
                        ->update(['effective_to' => $effectiveFrom->toDateString()]);
                }
            }

            $id = DB::table('standing_schedules')->insertGetId([
                'patient_id' => $patient->id,
                'shift_id' => $attributes['shift_id'],
                'station_id' => $attributes['station_id'] ?? null,
                'weekday_mask' => $attributes['weekday_mask'],
                'effective_from' => $effectiveFrom->toDateString(),
                'effective_to' => $attributes['effective_to'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $actor->id,
                'created_at' => Carbon::now(),
            ]);

            $row = DB::table('standing_schedules')->where('id', $id)->first();

            if ($row === null) {
                throw new DomainRuleException('Standing schedule vanished immediately after insert.');
            }

            return $row;
        });
    }

    /** The pattern in force for a patient on a given date, or null. */
    public function standingScheduleOn(Patient $patient, CarbonInterface $date): ?object
    {
        return DB::table('standing_schedules')
            ->where('patient_id', $patient->id)
            ->where('effective_from', '<=', $date->toDateString())
            ->where('effective_to_x', '>', $date->toDateString())
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * Materialise the scheduled sessions for one date.
     *
     * Idempotent: a patient who already has a session on that date is skipped,
     * so running it twice does not double-book anyone and re-running after a
     * manual edit does not undo the edit.
     *
     * @return array{created: int, skipped: int, clashes: list<string>}
     */
    public function generateDay(CarbonInterface $date, Staff $actor): array
    {
        $dateString = $date->toDateString();
        $bit = $this->weekdayBit($date);

        $exceptions = DB::table('schedule_exceptions')
            ->where('exception_date', $dateString)
            ->get()
            ->keyBy(fn (object $row): string => $row->patient_id.':'.$row->kind);

        // A holiday cancels the whole day. It is recorded per patient in this
        // schema, so a centre-wide closure is a row per patient -- but if any
        // holiday row exists for a patient, that patient is not scheduled.
        $due = DB::table('standing_schedules as ss')
            ->join('patients as p', 'p.id', '=', 'ss.patient_id')
            ->where('ss.effective_from', '<=', $dateString)
            ->where('ss.effective_to_x', '>', $dateString)
            ->whereRaw('(ss.weekday_mask & ?) > 0', [1 << $bit])
            // Only patients who are actually dialysing here.
            ->whereIn('p.status', ['active', 'hospitalised'])
            ->whereNull('p.deleted_at')
            ->get(['ss.patient_id', 'ss.shift_id', 'ss.station_id']);

        $created = 0;
        $skipped = 0;
        $clashes = [];

        foreach ($due as $row) {
            if ($exceptions->has($row->patient_id.':cancel') || $exceptions->has($row->patient_id.':holiday')) {
                $skipped++;

                continue;
            }

            $shiftId = $row->shift_id;
            $stationId = $row->station_id;

            if ($reschedule = $exceptions->get($row->patient_id.':reschedule')) {
                $shiftId = $reschedule->new_shift_id ?? $shiftId;
                $stationId = $reschedule->new_station_id ?? $stationId;
            }

            $alreadyBooked = TreatmentSession::query()
                ->where('patient_id', $row->patient_id)
                ->where('session_date', $dateString)
                ->exists();

            if ($alreadyBooked) {
                $skipped++;

                continue;
            }

            try {
                TreatmentSession::create([
                    'patient_id' => $row->patient_id,
                    'session_date' => $dateString,
                    'shift_id' => $shiftId,
                    'station_id' => $stationId,
                    'status' => 'scheduled',
                    'modality' => 'hd',
                    'created_by' => $actor->id,
                ]);

                $created++;
            } catch (QueryException $e) {
                // ts_slot_station_uq: the chair is taken for that shift. Report
                // it rather than silently seating this patient somewhere else --
                // choosing the replacement chair is the charge nurse's call.
                if (! $this->isSlotClash($e)) {
                    throw $e;
                }

                $clashes[] = $this->describeClash($row->patient_id, $stationId, $shiftId, $dateString);
                $skipped++;
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'clashes' => $clashes];
    }

    /**
     * Book one session by hand.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function book(Patient $patient, array $attributes, Staff $actor): TreatmentSession
    {
        try {
            return TreatmentSession::create($attributes + [
                'patient_id' => $patient->id,
                'status' => 'scheduled',
                'created_by' => $actor->id,
            ]);
        } catch (QueryException $e) {
            if ($this->isSlotClash($e)) {
                throw new DomainRuleException(
                    $this->describeClash(
                        $patient->id,
                        $attributes['station_id'] ?? null,
                        $attributes['shift_id'] ?? null,
                        (string) $attributes['session_date'],
                    ),
                    previous: $e,
                );
            }

            throw $e;
        }
    }

    /**
     * The board for a date, off v_daily_board.
     *
     * The view exposes shift_code rather than shift_id, so `shifts` is joined
     * back for two reasons: to filter by shift, and to order chronologically.
     * Ordering by the code alone would put NOC before PM.
     *
     * @return list<object>
     */
    public function board(CarbonInterface $date, ?string $shiftCode = null): array
    {
        return array_values(
            DB::table('v_daily_board as b')
                ->leftJoin('shifts as sh', 'sh.code', '=', 'b.shift_code')
                ->where('b.session_date', $date->toDateString())
                ->when($shiftCode !== null, fn ($query) => $query->where('b.shift_code', $shiftCode))
                ->orderBy('sh.sort_order')
                ->orderBy('b.station_code')
                ->get(['b.*'])
                ->all()
        );
    }

    /** Bit position for a date, with Monday at 0 as the schema specifies. */
    public function weekdayBit(CarbonInterface $date): int
    {
        // Carbon's dayOfWeek is Sunday=0..Saturday=6; the mask is Monday=0.
        return ($date->dayOfWeek + 6) % 7;
    }

    private function isSlotClash(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062
            && str_contains((string) $e->getMessage(), 'ts_slot_station_uq');
    }

    private function describeClash(int $patientId, ?int $stationId, ?int $shiftId, string $date): string
    {
        $station = $stationId === null ? '(no chair)' : (string) DB::table('stations')->where('id', $stationId)->value('code');
        $shift = $shiftId === null ? '(no shift)' : (string) DB::table('shifts')->where('id', $shiftId)->value('code');
        $mrn = (string) DB::table('patients')->where('id', $patientId)->value('mrn');

        $occupant = DB::table('treatment_sessions as ts')
            ->join('patients as p', 'p.id', '=', 'ts.patient_id')
            ->where('ts.session_date', $date)
            ->where('ts.station_id', $stationId)
            ->where('ts.shift_id', $shiftId)
            ->whereNotIn('ts.status', ['cancelled', 'missed', 'refused'])
            ->value('p.mrn');

        return "Chair {$station} is already taken in the {$shift} shift on {$date}"
            .($occupant === null ? '' : " by {$occupant}")
            .", so {$mrn} was not scheduled.";
    }
}
