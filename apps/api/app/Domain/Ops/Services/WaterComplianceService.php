<?php

declare(strict_types=1);

namespace App\Domain\Ops\Services;

use App\Domain\Core\Models\Staff;
use App\Domain\Core\Services\FacilityCalendar;
use App\Domain\Ops\Models\WaterDailyLog;
use App\Support\Exceptions\DomainRuleException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Invariant 9: total chlorine over the action limit blocks the day's first
 * session.
 *
 * Chlorine and chloramine pass through an RO membrane. When the carbon beds are
 * exhausted they reach the dialysate, and across a dialyzer membrane that causes
 * haemolysis -- which is why the test is done before the first patient is
 * needled, not after.
 *
 * The limit is 0.1 ppm total chlorine. That figure is not invented here: it is
 * written into database/schema/mysql-schema.sql on water_daily_logs
 * ("action limit 0.1 ppm") and into v_water_exceptions, and it is the AAMI/ISO
 * 23500 action level for dialysis water. It is defined once, below, so the
 * preventive check and the detective view cannot drift apart.
 *
 * v_water_exceptions remains the detective control. This service is the
 * preventive half: it refuses to let the first treatment of the day start until
 * a passing check exists.
 */
final class WaterComplianceService
{
    /**
     * Total chlorine action limit, ppm (mg/L).
     *
     * Source: database/schema/mysql-schema.sql, water_daily_logs.total_chlorine_ppm
     * and v_water_exceptions, both of which use 0.1; AAMI/ISO 23500 for dialysis
     * water quality.
     */
    public const TOTAL_CHLORINE_LIMIT_PPM = 0.1;

    /**
     * How far ahead of the server's clock a stated check time may be before it
     * is refused as being in the future. Absorbs a device clock that runs a
     * little fast; a technical allowance, not a clinical figure.
     */
    private const CLOCK_SKEW_SECONDS = 120;

    public function __construct(private readonly FacilityCalendar $calendar) {}

    /**
     * Record a pre-dialysis check.
     *
     * `is_out_of_range` is set here rather than trusted from the client: whether
     * a reading breaches is a property of the reading, and a tablet that gets it
     * wrong would leave a breach looking clean in wdl_breach_idx.
     *
     * The time is the server's unless the caller states one, and a stated one is
     * converted to UTC here. Eloquent would otherwise store "05:30+08:00" as the
     * wall-clock 05:30 -- eight hours out, and on the wrong side of the unit's
     * midnight. A time in the future is refused: the gate trusts the latest
     * check of the day, so a future-dated passing reading would hide a later
     * failing one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordDailyLog(array $attributes, Staff $actor): WaterDailyLog
    {
        $chlorine = $attributes['total_chlorine_ppm'] ?? null;
        $now = Carbon::now();
        $loggedAt = isset($attributes['logged_at']) ? Carbon::parse((string) $attributes['logged_at'])->utc() : $now->copy();

        if ($loggedAt->greaterThan($now->copy()->addSeconds(self::CLOCK_SKEW_SECONDS))) {
            throw new DomainRuleException(
                'A water check cannot be recorded for a time that has not happened yet ('.$loggedAt->toIso8601String().').'
            );
        }

        $shiftId = null;

        if (isset($attributes['shift_code'])) {
            $shiftId = DB::table('shifts')->where('code', $attributes['shift_code'])->value('id');
        }

        unset($attributes['shift_code']);

        $log = new WaterDailyLog;
        $log->fill([
            ...$attributes,
            'logged_at' => $loggedAt,
            'shift_id' => $shiftId,
            'logged_by' => $actor->id,
            'is_out_of_range' => $this->breaches($chlorine),
            // Set here rather than left to the column default, which is the MySQL
            // server's clock -- UTC+8 on some hosts -- not UTC.
            'created_at' => $now,
        ]);
        $log->save();

        return $log;
    }

    /**
     * The checks logged on one of the unit's days, newest first, with who
     * logged each and on which system.
     *
     * @return list<array<string, mixed>>
     */
    public function logsFor(CarbonInterface $date): array
    {
        [$start, $end] = $this->calendar->dayWindow($date);

        return array_values(DB::table('water_daily_logs as w')
            ->join('water_systems as ws', 'ws.id', '=', 'w.water_system_id')
            ->leftJoin('shifts as sh', 'sh.id', '=', 'w.shift_id')
            ->leftJoin('staff as s', 's.id', '=', 'w.logged_by')
            ->where('w.logged_at', '>=', $start)
            ->where('w.logged_at', '<', $end)
            ->orderByDesc('w.logged_at')
            ->orderByDesc('w.id')
            ->get([
                'w.logged_at', 'w.created_at', 'ws.name as system_name', 'sh.code as shift_code',
                'w.total_chlorine_ppm', 'w.free_chlorine_ppm', 'w.ph', 'w.hardness_ppm',
                'w.feed_pressure_psi', 'w.product_pressure_psi', 'w.reject_pressure_psi',
                'w.feed_conductivity_us', 'w.product_conductivity_us', 'w.rejection_pct', 'w.temperature_c',
                'w.softener_salt_ok', 'w.carbon_tank_ok', 'w.is_out_of_range', 'w.action_taken',
                's.full_name as logged_by',
            ])
            ->map(function (object $row): array {
                $raw = (array) $row;
                unset($raw['created_at']);

                // The converted values are on the LEFT of `+`, which keeps the
                // left operand on a key collision -- they must win.
                return [
                    // Raw rows carry MySQL's zoneless DATETIME text; stored UTC,
                    // so it leaves as UTC (CLAUDE.md, MySQL rule 11).
                    'logged_at' => Carbon::parse((string) $row->logged_at, 'UTC')->toIso8601String(),
                    'recorded_at' => $row->created_at === null ? null : Carbon::parse((string) $row->created_at, 'UTC')->toIso8601String(),
                    'is_out_of_range' => (bool) $row->is_out_of_range,
                    'softener_salt_ok' => $row->softener_salt_ok === null ? null : (bool) $row->softener_salt_ok,
                    'carbon_tank_ok' => $row->carbon_tank_ok === null ? null : (bool) $row->carbon_tank_ok,
                ] + $raw;
            })
            ->all());
    }

    /**
     * The shifts a check can be recorded before, by code.
     *
     * @return list<array{code: string, name: string}>
     */
    public function shifts(): array
    {
        return array_values(DB::table('shifts')
            ->orderBy('sort_order')
            ->get(['code', 'name'])
            ->map(fn (object $row): array => ['code' => (string) $row->code, 'name' => (string) $row->name])
            ->all());
    }

    /**
     * The water systems a check can be recorded against.
     *
     * @return list<array{id: int, name: string}>
     */
    public function activeSystems(): array
    {
        return array_values(DB::table('water_systems')
            ->where('is_active', 1)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all());
    }

    /**
     * A reading breaches when total chlorine is ABOVE the action limit -- strictly.
     *
     * `>`, not `>=`, to match v_water_exceptions (`WHERE total_chlorine_ppm > 0.1`)
     * and CLAUDE.md's invariant table. This comment once said "at or above"; code
     * "fixed" to match it would block a 0.1 reading that the view does not flag,
     * and the two layers of invariant 9 would disagree.
     */
    public function breaches(mixed $totalChlorinePpm): bool
    {
        if ($totalChlorinePpm === null) {
            return false;
        }

        return (float) $totalChlorinePpm > self::TOTAL_CHLORINE_LIMIT_PPM;
    }

    /**
     * Is the unit cleared to dialyse on this date?
     *
     * Cleared means: a check was logged that day, and the most recent one is
     * within limits. No check at all is *not* clearance -- an unmeasured carbon
     * bed is not a safe one, and treating silence as a pass is precisely how this
     * control fails in practice.
     *
     * @return array{cleared: bool, reason: string|null, checked_at: string|null, total_chlorine_ppm: string|null}
     */
    public function clearanceFor(CarbonInterface $date): array
    {
        $latest = $this->onDay($date)
            ->orderByDesc('logged_at')
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            return [
                'cleared' => false,
                'reason' => 'No water check has been logged for '.$date->toDateString()
                    .'. Record a total-chlorine reading on the Water screen before the first treatment.',
                'checked_at' => null,
                'total_chlorine_ppm' => null,
            ];
        }

        if ($latest->total_chlorine_ppm === null) {
            return [
                'cleared' => false,
                'reason' => 'The water check for '.$date->toDateString().' did not record total chlorine.',
                'checked_at' => $latest->logged_at->toIso8601String(),
                'total_chlorine_ppm' => null,
            ];
        }

        if ($this->breaches($latest->total_chlorine_ppm)) {
            return [
                'cleared' => false,
                'reason' => sprintf(
                    'Total chlorine is %s ppm, above the %s ppm action limit. Do not dialyse until the carbon beds are corrected and a passing check is logged.',
                    $latest->total_chlorine_ppm,
                    self::TOTAL_CHLORINE_LIMIT_PPM,
                ),
                'checked_at' => $latest->logged_at->toIso8601String(),
                'total_chlorine_ppm' => (string) $latest->total_chlorine_ppm,
            ];
        }

        return [
            'cleared' => true,
            'reason' => null,
            'checked_at' => $latest->logged_at->toIso8601String(),
            'total_chlorine_ppm' => (string) $latest->total_chlorine_ppm,
        ];
    }

    /**
     * Refuse to start the first treatment of a day the water has not cleared.
     *
     * Only the first: once the day's check has passed and treatment is under way,
     * re-testing mid-shift is an operational decision rather than something to
     * enforce on every needle.
     *
     * The early return is conditional on a passing check actually existing for
     * the day. An earlier version returned as soon as ANY session had started,
     * reasoning that the check "has been satisfied" -- true only if that first
     * session came through here. One that got in any other way (an import, a
     * console command, a direct insert, or the offline session.upsert that used to
     * accept status and started_at) switched this gate off for the whole unit for
     * the rest of the day, and it failed OPEN. That someone has started is not
     * evidence the water was tested; a passing check on record is.
     *
     * @throws DomainRuleException
     */
    public function assertClearedToStart(CarbonInterface $date): void
    {
        if ($this->startGate($date)['start_allowed']) {
            return;
        }

        throw new DomainRuleException(
            $this->clearanceFor($date)['reason'] ?? 'Water compliance has not been established for today.'
        );
    }

    /**
     * What the start gate would decide for a day, without enforcing it -- the
     * one decision assertClearedToStart() enforces, so a screen can say what
     * will happen rather than infer it.
     *
     * The two can differ from "is the latest reading within limits". Once
     * treatment is under way and the day has had a passing check, a later
     * failing reading does not stop the next start (the unit's "not every
     * needle" policy, still an open question in CLAUDE.md). A Water screen that
     * said "the next start will be refused" at that point would be telling the
     * unit a control exists that does not.
     *
     * @return array{treatment_started: bool, start_allowed: bool}
     */
    public function startGate(CarbonInterface $date): array
    {
        $started = $this->hasStartedTreatmentOn($date);

        return [
            'treatment_started' => $started,
            'start_allowed' => ($started && $this->hadPassingCheckOn($date)) || $this->clearanceFor($date)['cleared'],
        ];
    }

    /** Has any treatment already been started on this date? */
    /**
     * Was a passing check logged at any point on this date?
     *
     * "At any point", not "most recently", on purpose. This is consulted only once
     * treatment is already under way, where the unit's policy is not to enforce a
     * mid-shift re-test -- so a later failing reading does not stop the next
     * needle. What this rules out is the day on which no passing check exists at
     * all. Uses the same boundary as breaches(): above the limit fails.
     */
    public function hadPassingCheckOn(CarbonInterface $date): bool
    {
        return $this->onDay($date)
            ->whereNotNull('total_chlorine_ppm')
            ->where('total_chlorine_ppm', '<=', self::TOTAL_CHLORINE_LIMIT_PPM)
            ->exists();
    }

    /**
     * Checks logged during one of the unit's calendar days.
     *
     * Bounded by the day's UTC window, never by DATE(logged_at). logged_at is
     * UTC and a session_date is the unit's local date; DATE() of a UTC instant
     * put a 05:30 Manila check on the previous day and refused the 06:00 shift.
     *
     * @return Builder<WaterDailyLog>
     */
    private function onDay(CarbonInterface $date): Builder
    {
        [$start, $end] = $this->calendar->dayWindow($date);

        return WaterDailyLog::query()
            ->where('logged_at', '>=', $start)
            ->where('logged_at', '<', $end);
    }

    public function hasStartedTreatmentOn(CarbonInterface $date): bool
    {
        return DB::table('treatment_sessions')
            ->where('session_date', $date->toDateString())
            ->whereNotNull('started_at')
            ->exists();
    }
}
