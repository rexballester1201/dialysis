<?php

declare(strict_types=1);

use App\Domain\Core\Services\FacilityCalendar;
use App\Support\Exceptions\DomainRuleException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| The unit's calendar
|--------------------------------------------------------------------------
| Instants are stored UTC; days are the unit's. Between local midnight and
| 08:00 in Manila every UTC-derived date was yesterday -- the water gate
| refused the morning shift, and a lot that expired yesterday stayed
| issuable. FacilityCalendar is the one place that turns an instant into a
| day, and these pin what it answers.
*/

uses(TestCase::class, RefreshDatabase::class);

function calendar(): FacilityCalendar
{
    return app(FacilityCalendar::class);
}

it('names the unit s date, not the UTC date, in the early morning', function () {
    unitIn('Asia/Manila');

    // 01:00 on the 11th in Manila is still the 10th in UTC.
    $this->travelTo(Carbon::parse('2030-03-10 17:00:00', 'UTC'));

    expect(calendar()->todayString())->toBe('2030-03-11')
        ->and(now()->toDateString())->toBe('2030-03-10');
});

it('gives a day as the UTC window from local midnight to local midnight', function () {
    unitIn('Asia/Manila');

    [$start, $end] = calendar()->dayWindow('2030-03-11');

    expect($start->toIso8601String())->toBe('2030-03-10T16:00:00+00:00')
        ->and($end->toIso8601String())->toBe('2030-03-11T16:00:00+00:00');
});

it('puts an instant on the unit s calendar date', function () {
    unitIn('Asia/Manila');

    expect(calendar()->dateOf(Carbon::parse('2030-03-10 21:30:00', 'UTC'))->toDateString())->toBe('2030-03-11')
        ->and(calendar()->dateOf(Carbon::parse('2030-03-10 15:59:59', 'UTC'))->toDateString())->toBe('2030-03-10');
});

it('keeps a daylight-saving day its real length', function () {
    // Built from local midnight to local midnight, not start + 24h: the day
    // the clocks go forward in New York is 23 hours long.
    unitIn('America/New_York');

    [$start, $end] = calendar()->dayWindow('2030-03-10');

    expect($start->diffInHours($end))->toEqual(23);
});

it('refuses to guess a day when the unit has no timezone', function () {
    DB::table('facilities')->delete();

    // Falling back to UTC would be the very error this class removes.
    expect(fn () => calendar()->todayString())->toThrow(DomainRuleException::class, 'timezone is not set');
});

it('refuses a timezone that is not one', function () {
    unitIn('Asia/Atlantis');

    expect(fn () => calendar()->todayString())->toThrow(DomainRuleException::class, 'is not a timezone');
});
