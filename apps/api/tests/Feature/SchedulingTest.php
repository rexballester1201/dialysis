<?php

declare(strict_types=1);

use App\Domain\Clinical\Models\TreatmentSession;
use App\Domain\Core\Models\Patient;
use App\Domain\Core\Models\Staff;
use App\Domain\Ops\Services\SchedulingService;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Standing schedules and the daily board
|--------------------------------------------------------------------------
| A patient holds a repeating pattern -- Mon/Wed/Fri AM, chair S-04 -- and the
| board for a date is that pattern plus whatever the ward changed by hand.
|
| weekday_mask is a 7-bit set with bit 0 = Monday: the schema replaced a Postgres
| text[] with a mask, so 21 = Mon/Wed/Fri.
*/

uses(TestCase::class, RefreshDatabase::class);

/** Mon/Wed/Fri. Bit 0 = Monday, so 1 + 4 + 16. */
const MON_WED_FRI = 21;

function chargeNurse(): Staff
{
    return staffWithRole('head_nurse');
}

function shiftId(string $code): int
{
    return (int) DB::table('shifts')->where('code', $code)->value('id');
}

function stationId(string $code): int
{
    return (int) DB::table('stations')->where('code', $code)->value('id');
}

/** The next date that falls on the given weekday, so tests never depend on today. */
function nextWeekday(int $isoDayOfWeek): Carbon
{
    return now()->startOfDay()->next($isoDayOfWeek);
}

it('maps Monday to bit 0 as the schema specifies', function () {
    $service = app(SchedulingService::class);

    expect($service->weekdayBit(nextWeekday(CarbonInterface::MONDAY)))->toBe(0)
        ->and($service->weekdayBit(nextWeekday(CarbonInterface::WEDNESDAY)))->toBe(2)
        ->and($service->weekdayBit(nextWeekday(CarbonInterface::SUNDAY)))->toBe(6);
});

it('opens a standing pattern and closes the previous one', function () {
    $patient = Patient::factory()->create();
    $nurse = chargeNurse();

    $this->actingAs($nurse, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/schedule", [
            'shift_id' => shiftId('AM'),
            'station_id' => stationId('S-04'),
            'weekday_mask' => MON_WED_FRI,
            'effective_from' => now()->subMonths(3)->toDateString(),
        ])
        ->assertCreated();

    // A second pattern from today. standing_schedules_bi rejects an overlap
    // outright, so the service has to close the first one in the same breath.
    $this->actingAs($nurse, 'sanctum')
        ->postJson("/api/v1/patients/{$patient->public_id}/schedule", [
            'shift_id' => shiftId('PM'),
            'station_id' => stationId('S-05'),
            'weekday_mask' => MON_WED_FRI,
            'effective_from' => now()->toDateString(),
        ])
        ->assertCreated();

    $rows = DB::table('standing_schedules')->where('patient_id', $patient->id)->orderBy('effective_from')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->effective_to)->toBe(now()->toDateString())
        ->and($rows[1]->effective_to)->toBeNull();
});

it('refuses an overlapping standing schedule at the database level', function () {
    $patient = Patient::factory()->create();

    DB::table('standing_schedules')->insert([
        'patient_id' => $patient->id,
        'shift_id' => shiftId('AM'),
        'weekday_mask' => MON_WED_FRI,
        'effective_from' => now()->subMonth()->toDateString(),
    ]);

    // The trigger is the backstop for anything that bypasses the service.
    expect(fn () => DB::table('standing_schedules')->insert([
        'patient_id' => $patient->id,
        'shift_id' => shiftId('PM'),
        'weekday_mask' => MON_WED_FRI,
        'effective_from' => now()->toDateString(),
    ]))->toThrow(QueryException::class);
});

it('generates the board for a day from the standing patterns', function () {
    $monday = nextWeekday(CarbonInterface::MONDAY);
    $nurse = chargeNurse();

    $mwf = Patient::factory()->create(['mrn' => 'MRN-90001']);
    $tts = Patient::factory()->create(['mrn' => 'MRN-90002']);

    app(SchedulingService::class)->setStandingSchedule($mwf, [
        'shift_id' => shiftId('AM'), 'station_id' => stationId('S-04'), 'weekday_mask' => MON_WED_FRI,
    ], $monday->copy()->subMonth(), $nurse);

    // Tue/Thu/Sat = bits 1, 3, 5 = 2 + 8 + 32.
    app(SchedulingService::class)->setStandingSchedule($tts, [
        'shift_id' => shiftId('AM'), 'station_id' => stationId('S-06'), 'weekday_mask' => 42,
    ], $monday->copy()->subMonth(), $nurse);

    $response = $this->actingAs($nurse, 'sanctum')
        ->postJson('/api/v1/board/generate', ['date' => $monday->toDateString()]);

    $response->assertOk()
        ->assertJsonPath('created', 1)
        ->assertJsonPath('clashes', []);

    $board = $this->actingAs($nurse, 'sanctum')
        ->getJson('/api/v1/board?date='.$monday->toDateString())
        ->assertOk()
        ->json('sessions');

    expect($board)->toHaveCount(1)
        ->and($board[0]['mrn'])->toBe('MRN-90001')
        ->and($board[0]['station_code'])->toBe('S-04')
        ->and($board[0]['shift_code'])->toBe('AM');
});

it('is idempotent: generating the same day twice does not double-book', function () {
    $monday = nextWeekday(CarbonInterface::MONDAY);
    $nurse = chargeNurse();
    $patient = Patient::factory()->create();

    app(SchedulingService::class)->setStandingSchedule($patient, [
        'shift_id' => shiftId('AM'), 'station_id' => stationId('S-04'), 'weekday_mask' => MON_WED_FRI,
    ], $monday->copy()->subMonth(), $nurse);

    $first = $this->actingAs($nurse, 'sanctum')
        ->postJson('/api/v1/board/generate', ['date' => $monday->toDateString()])->assertOk();

    $second = $this->actingAs($nurse, 'sanctum')
        ->postJson('/api/v1/board/generate', ['date' => $monday->toDateString()])->assertOk();

    expect($first->json('created'))->toBe(1)
        ->and($second->json('created'))->toBe(0)
        ->and($second->json('skipped'))->toBe(1)
        ->and(TreatmentSession::query()->where('patient_id', $patient->id)->count())->toBe(1);
});

it('honours a cancellation for the day', function () {
    $monday = nextWeekday(CarbonInterface::MONDAY);
    $nurse = chargeNurse();
    $patient = Patient::factory()->create();

    app(SchedulingService::class)->setStandingSchedule($patient, [
        'shift_id' => shiftId('AM'), 'station_id' => stationId('S-04'), 'weekday_mask' => MON_WED_FRI,
    ], $monday->copy()->subMonth(), $nurse);

    DB::table('schedule_exceptions')->insert([
        'patient_id' => $patient->id,
        'exception_date' => $monday->toDateString(),
        'kind' => 'cancel',
        'reason' => 'admitted overnight',
        'created_by' => $nurse->id,
    ]);

    $this->actingAs($nurse, 'sanctum')
        ->postJson('/api/v1/board/generate', ['date' => $monday->toDateString()])
        ->assertOk()
        ->assertJsonPath('created', 0)
        ->assertJsonPath('skipped', 1);

    expect(TreatmentSession::query()->count())->toBe(0);
});

it('moves a patient to the chair a reschedule names', function () {
    $monday = nextWeekday(CarbonInterface::MONDAY);
    $nurse = chargeNurse();
    $patient = Patient::factory()->create();

    app(SchedulingService::class)->setStandingSchedule($patient, [
        'shift_id' => shiftId('AM'), 'station_id' => stationId('S-04'), 'weekday_mask' => MON_WED_FRI,
    ], $monday->copy()->subMonth(), $nurse);

    DB::table('schedule_exceptions')->insert([
        'patient_id' => $patient->id,
        'exception_date' => $monday->toDateString(),
        'kind' => 'reschedule',
        'new_shift_id' => shiftId('PM'),
        'new_station_id' => stationId('S-09'),
        'created_by' => $nurse->id,
    ]);

    $this->actingAs($nurse, 'sanctum')
        ->postJson('/api/v1/board/generate', ['date' => $monday->toDateString()])
        ->assertOk()
        ->assertJsonPath('created', 1);

    $board = $this->actingAs($nurse, 'sanctum')
        ->getJson('/api/v1/board?date='.$monday->toDateString())->assertOk()->json('sessions');

    expect($board[0]['shift_code'])->toBe('PM')
        ->and($board[0]['station_code'])->toBe('S-09');
});

it('reports a chair clash by name instead of seating someone at random', function () {
    $monday = nextWeekday(CarbonInterface::MONDAY);
    $nurse = chargeNurse();

    $first = Patient::factory()->create(['mrn' => 'MRN-91001']);
    $second = Patient::factory()->create(['mrn' => 'MRN-91002']);

    // Both patterns claim S-04 in the AM shift. Invariant 5 says one chair holds
    // one patient per shift per day.
    foreach ([$first, $second] as $patient) {
        app(SchedulingService::class)->setStandingSchedule($patient, [
            'shift_id' => shiftId('AM'), 'station_id' => stationId('S-04'), 'weekday_mask' => MON_WED_FRI,
        ], $monday->copy()->subMonth(), $nurse);
    }

    $response = $this->actingAs($nurse, 'sanctum')
        ->postJson('/api/v1/board/generate', ['date' => $monday->toDateString()])
        ->assertOk();

    expect($response->json('created'))->toBe(1)
        ->and($response->json('clashes'))->toHaveCount(1)
        ->and($response->json('clashes.0'))->toContain('S-04')
        ->and($response->json('clashes.0'))->toContain('MRN-91002');

    // Only one of them got the chair, and the database would have stopped a
    // second even if this service had not.
    expect(TreatmentSession::query()->where('session_date', $monday->toDateString())->count())->toBe(1);
});

it('does not schedule a patient who has transferred out', function () {
    $monday = nextWeekday(CarbonInterface::MONDAY);
    $nurse = chargeNurse();
    $patient = Patient::factory()->create(['status' => 'active']);

    app(SchedulingService::class)->setStandingSchedule($patient, [
        'shift_id' => shiftId('AM'), 'station_id' => stationId('S-04'), 'weekday_mask' => MON_WED_FRI,
    ], $monday->copy()->subMonth(), $nurse);

    $patient->forceFill(['status' => 'transferred_out'])->save();

    $this->actingAs($nurse, 'sanctum')
        ->postJson('/api/v1/board/generate', ['date' => $monday->toDateString()])
        ->assertOk()
        ->assertJsonPath('created', 0);

    expect(TreatmentSession::query()->count())->toBe(0);
});

it('offers only chairs the patient cohort permits', function () {
    $patient = Patient::factory()->hbvReactive()->create();
    $monday = nextWeekday(CarbonInterface::MONDAY);

    $body = $this->actingAs(chargeNurse(), 'sanctum')
        ->getJson("/api/v1/patients/{$patient->public_id}/available-stations?date={$monday->toDateString()}&shift_id=".shiftId('AM'))
        ->assertOk()
        ->json();

    $codes = array_column($body['stations'], 'code');

    expect($body['cohort'])->toBe('hbv')
        // ISO-B1/B2 are the HBV chairs; the general S-* chairs are designated clean.
        ->and($codes)->toContain('ISO-B1')
        ->and($codes)->not->toContain('S-01');
});

it('will not let a physician book the floor', function () {
    // Scheduling is a charge-nurse function; TreatmentSessionPolicy::create says so.
    $this->actingAs(staffWithRole('nephrologist'), 'sanctum')
        ->postJson('/api/v1/board/generate', ['date' => now()->toDateString()])
        ->assertForbidden();
});

it('opens the board on the unit s today when no date is asked for', function () {
    unitIn('Asia/Manila');
    $this->travelTo(Carbon::parse('2030-03-11 06:00:00', 'Asia/Manila'));

    $this->actingAs(chargeNurse(), 'sanctum')
        ->getJson('/api/v1/board')
        ->assertOk()
        ->assertJsonPath('date', '2030-03-11');
});
