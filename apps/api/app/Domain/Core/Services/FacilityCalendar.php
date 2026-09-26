<?php

declare(strict_types=1);

namespace App\Domain\Core\Services;

use App\Support\Exceptions\DomainRuleException;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The unit's calendar: which day it is here, and when that day starts and
 * ends in the UTC the database stores.
 *
 * Every instant is stored UTC (CLAUDE.md, MySQL rule 3), but a dialysis day is
 * local. The board, a session's `session_date`, "the day's first treatment"
 * that invariant 9 gates, and the date a lot expires on are all the unit's
 * calendar days. Asking PHP for "today" answers in UTC, and a unit in Manila
 * is eight hours ahead of that: between midnight and 08:00 every UTC-derived
 * date was yesterday. The water gate compared a 05:30 check's UTC date with
 * the 06:00 session's local date and refused a unit that had tested its water.
 *
 * So any code that turns an instant into a calendar day, or needs "today",
 * asks here. The zone is facilities.timezone -- what the unit set in Settings.
 *
 * Dates come back as UTC-midnight Carbons carrying the local calendar date:
 * the same shape `$request->date('date')` already produces, so a default and a
 * date the client sent are interchangeable.
 */
final class FacilityCalendar
{
    /** The unit's zone, e.g. Asia/Manila. */
    public function timezone(): string
    {
        $zone = DB::table('facilities')->where('id', 1)->value('timezone');

        if (! is_string($zone) || $zone === '') {
            // No fallback on purpose. Falling back to UTC is exactly the
            // eight-hour error this class exists to remove.
            throw new DomainRuleException(
                "The unit's timezone is not set, so there is no telling which day it is. "
                .'The facility row is missing -- import 03-seed.sql, or set the timezone in Settings.'
            );
        }

        try {
            new \DateTimeZone($zone);
        } catch (Throwable) {
            throw new DomainRuleException("The unit's timezone '{$zone}' is not a timezone. Correct it in Settings.");
        }

        return $zone;
    }

    /** Today, on the unit's calendar. */
    public function today(): Carbon
    {
        return $this->dateOf(Carbon::now());
    }

    public function todayString(): string
    {
        return $this->today()->toDateString();
    }

    /** The unit's calendar date that an instant falls on. */
    public function dateOf(CarbonInterface $instant): Carbon
    {
        $local = Carbon::instance($instant)->setTimezone($this->timezone());

        return Carbon::parse($local->toDateString(), 'UTC')->startOfDay();
    }

    /**
     * The UTC instants a calendar day on the unit's clock runs between:
     * [start, end). Compare stored instants against these, never DATE() them.
     *
     * Built from local midnight to local midnight rather than start + 24h, so a
     * zone with daylight saving still gets its 23- and 25-hour days right.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function dayWindow(CarbonInterface|string $date): array
    {
        $day = $date instanceof CarbonInterface ? $date->toDateString() : Carbon::parse($date)->toDateString();

        $start = Carbon::parse($day, $this->timezone())->startOfDay();
        $end = $start->copy()->addDay();

        return [$start->utc(), $end->utc()];
    }
}
