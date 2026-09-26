<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every test runs on a unit whose calendar is UTC, unless it says otherwise.
     *
     * "Today" is the unit's day (FacilityCalendar), and the seeded facility is
     * in Asia/Manila -- but the suite builds its dates from now(), in UTC. From
     * 16:00 to 24:00 UTC the two calendars name different days, so a test that
     * leaned on a default date would pass or fail by the time it ran. That is a
     * flaky test, which CLAUDE.md counts as a bug.
     *
     * This does not hide the Manila behaviour: the tests about the day boundary
     * set Asia/Manila themselves and pin the clock to the hours where the two
     * calendars disagree. Rolled back with the rest of each test's transaction.
     */
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('facilities')->where('id', 1)->update(['timezone' => 'UTC']);
    }

    /**
     * The baseline creates 12 views. Without this, migrate:fresh drops only the
     * tables, the views survive, and reloading the schema fails on the first
     * CREATE VIEW that already exists.
     */
    protected bool $dropViews = true;

    /**
     * Reference data -- stations, shifts, roles, medication_refs, benefit
     * programs -- is not fixture data. The invariants under test read it. It is
     * loaded once by migrate:fresh, outside the per-test transaction.
     */
    protected bool $seed = true;

    /*
     * The baseline is loaded through a stripped copy so its `USE dialysis;` does
     * not redirect the test run into the development database. That cannot be
     * done by overriding migrateFreshUsing() here -- RefreshDatabase brings its
     * own via a trait, and a trait method beats an inherited one -- so it is
     * injected as the migrate command starts. See AppServiceProvider and
     * App\Support\Database\BaselineSchema.
     */
}
