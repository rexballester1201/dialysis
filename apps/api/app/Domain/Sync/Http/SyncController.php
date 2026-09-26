<?php

declare(strict_types=1);

namespace App\Domain\Sync\Http;

use App\Domain\Core\Models\Staff;
use App\Domain\Core\Services\FacilityCalendar;
use App\Domain\Sync\SyncBatchProcessor;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

final class SyncController extends Controller
{
    public function __construct(
        private readonly SyncBatchProcessor $processor,
        private readonly FacilityCalendar $calendar,
    ) {}

    /**
     * Everything the tablet needs to run a full shift with no network.
     * Deliberately scoped to one date so the payload stays small enough to
     * cache on a low-end Android tablet.
     */
    public function bootstrap(Request $request): JsonResponse
    {
        // The unit's today, not the server's: a tablet that asks without a
        // date at 06:00 in Manila wants this morning's board, not yesterday's.
        $date = $request->date('date')?->toDateString() ?? $this->calendar->todayString();
        $shiftId = $request->integer('shift_id') ?: null;

        $sessions = DB::table('treatment_sessions as ts')
            ->join('patients as p', 'p.id', '=', 'ts.patient_id')
            ->leftJoin('v_patient_cohort as c', 'c.patient_id', '=', 'p.id')
            ->leftJoin('stations as st', 'st.id', '=', 'ts.station_id')
            ->leftJoin('machines as m', 'm.id', '=', 'ts.machine_id')
            ->where('ts.session_date', $date)
            ->when($shiftId, fn ($q) => $q->where('ts.shift_id', $shiftId))
            ->whereNotIn('ts.status', ['cancelled'])
            ->get([
                'ts.public_id', 'ts.status', 'ts.shift_id', 'ts.session_date',
                'ts.started_at', 'ts.ended_at',
                'ts.locked_at', 'ts.planned_duration_min', 'ts.planned_uf_ml',
                'ts.pre_weight_kg', 'ts.dry_weight_kg',
                'st.code as station_code', 'm.asset_tag as machine',
                'p.public_id as patient_public_id', 'p.mrn', 'p.full_name',
                'p.birth_date', 'p.sex', 'c.cohort',
            ])
            ->map($this->withIsoTimes(...));

        $patientIds = DB::table('treatment_sessions')
            ->where('session_date', $date)->pluck('patient_id');

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'date' => $date,
            'sessions' => $sessions,
            'prescriptions' => DB::table('v_current_prescription')
                ->whereIn('patient_id', $patientIds)->get(),
            'allergies' => DB::table('allergies')
                ->whereIn('patient_id', $patientIds)->where('is_active', 1)->get(),
            'medication_orders' => DB::table('medication_orders')
                ->whereIn('patient_id', $patientIds)->where('status', 'active')->get(),
            'reference' => [
                'event_refs' => DB::table('event_refs')->get(),
                'medication_refs' => DB::table('medication_refs')->get(),
                'shifts' => DB::table('shifts')->where('is_active', 1)->get(),
            ],
        ]);
    }

    /**
     * Timestamps that leave here carry their offset.
     *
     * The query builder hands back MySQL's own DATETIME text -- "2026-08-20
     * 04:07:21.819", with nothing saying which zone that is. A browser parsing
     * a naive string like that reads it as *local* time, so a tablet in Manila
     * computed elapsed treatment minutes eight hours out and stamped every
     * observation with the wrong minute of the run. Everything is stored UTC
     * (facilities.timezone is for display), so it leaves here as UTC.
     *
     * Eloquent-backed endpoints already do this; this one is a raw query and
     * had to be told.
     */
    private function withIsoTimes(object $row): object
    {
        foreach (['started_at', 'ended_at', 'locked_at'] as $column) {
            if (($row->{$column} ?? null) !== null) {
                $row->{$column} = Carbon::parse((string) $row->{$column}, 'UTC')->toIso8601String();
            }
        }

        return $row;
    }

    /** Replay the tablet's outbox. Safe to call repeatedly with the same batch. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'batch_uuid' => ['required', 'string', 'size:26'],
            'device_id' => ['required', 'string', 'max:64'],
            'operations' => ['required', 'array', 'max:500'],
            'operations.*.op_uuid' => ['required', 'string', 'size:26'],
            'operations.*.type' => ['required', 'string', 'max:40'],
            // `present`, not `required`: Laravel treats an empty array as empty,
            // so `required` would 422 the entire batch because one operation
            // carried an empty payload. A malformed operation must be adjudicated
            // and rejected on its own -- one bad row may never sink a batch, or a
            // nurse loses a shift of charting. SyncBatchProcessor does that.
            'operations.*.payload' => ['present', 'array'],
        ]);

        $actor = $request->user();

        if (! $actor instanceof Staff) {
            abort(401);
        }

        $result = $this->processor->process(
            $validated['batch_uuid'],
            $validated['device_id'],
            $actor,
            $validated['operations'],
        );

        // 200 even when some operations were rejected: the batch itself was
        // received and adjudicated. Per-operation verdicts are in the body.
        return response()->json($result);
    }
}
