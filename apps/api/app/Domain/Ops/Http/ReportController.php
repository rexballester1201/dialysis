<?php

declare(strict_types=1);

namespace App\Domain\Ops\Http;

use App\Domain\Clinical\Adequacy;
use App\Domain\Core\Services\FacilityCalendar;
use App\Domain\Ops\Services\DetectiveControlReader;
use App\Domain\Ops\Services\QualitySummariser;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The reporting surface.
 *
 * One thing to know about the quality report: it reads
 * `monthly_quality_summaries`, not `v_monthly_quality`. The view is the
 * definition of record and the summary is a nightly copy of it, because the view
 * aggregates every session row before it can answer anything (CLAUDE.md, MySQL
 * rule 7). The response says when the copy was last rebuilt, and offers the view
 * directly for anyone who needs the live figure.
 */
final class ReportController extends Controller
{
    public function __construct(
        private readonly DetectiveControlReader $controls,
        private readonly QualitySummariser $summariser,
        private readonly FacilityCalendar $calendar,
    ) {}

    /**
     * Adequacy and event rates by month.
     *
     * `?live=1` bypasses the summary and reads the view. Slower, and exactly
     * right when someone is checking a figure they are about to act on.
     */
    public function quality(Request $request): JsonResponse
    {
        $this->authorize('water.view');

        $month = Carbon::parse($request->string('month')->toString() ?: $this->calendar->todayString())
            ->startOfMonth()->toDateString();

        $live = $request->boolean('live');

        $rows = $live
            ? DB::table('v_monthly_quality as q')
                ->join('patients as p', 'p.id', '=', 'q.patient_id')
                ->where('q.month', $month)
                ->orderBy('p.last_name')
                ->get(['p.mrn', 'p.full_name', 'q.sessions', 'q.completed', 'q.missed', 'q.shortened',
                    'q.avg_ktv', 'q.avg_urr', 'q.avg_idwg_kg', 'q.avg_duration_min',
                    'q.sessions_with_hypotension', 'q.sessions_with_reportable_event'])
            : DB::table('monthly_quality_summaries as s')
                ->join('patients as p', 'p.id', '=', 's.patient_id')
                ->where('s.month', $month)
                ->orderBy('p.last_name')
                ->get(['p.mrn', 'p.full_name', 's.sessions', 's.completed', 's.missed', 's.shortened',
                    's.avg_ktv', 's.avg_urr', 's.avg_idwg_kg', 's.avg_duration_min',
                    's.sessions_with_hypotension', 's.sessions_with_reportable_event']);

        return response()->json([
            'month' => $month,
            'source' => $live ? 'v_monthly_quality (live)' : 'monthly_quality_summaries (nightly)',
            // How stale these numbers are. A dashboard that cannot say invites
            // someone to trust it further than they should.
            'summarised_at' => $live ? null : $this->summariser->lastSummarisedAt(),
            'targets' => [
                'ktv' => Adequacy::KTV_TARGET,
                'urr_pct' => Adequacy::URR_TARGET_PCT,
            ],
            'patients' => $rows,
        ]);
    }

    /** Invariant 1, after the fact. */
    public function cohortViolations(Request $request): JsonResponse
    {
        $this->authorize('water.view');

        return response()->json([
            'from' => $request->string('from')->toString() ?: $this->calendar->todayString(),
            'violations' => $this->controls->cohortViolations(
                $request->string('from')->toString() ?: null
            ),
        ]);
    }

    /** Invariant 9, after the fact. */
    public function waterExceptions(): JsonResponse
    {
        $this->authorize('water.view');

        return response()->json(['exceptions' => $this->controls->waterExceptions()]);
    }

    /** Invariant 8, after the fact. */
    public function dialyzerExceptions(): JsonResponse
    {
        $this->authorize('water.view');

        return response()->json(['dialyzers' => $this->controls->dialyzerViolations()]);
    }

    /** Chair occupancy, for deciding whether the unit needs another shift. */
    public function utilisation(Request $request): JsonResponse
    {
        $this->authorize('water.view');

        $from = $request->date('from') ?? Carbon::now()->startOfMonth();
        $to = $request->date('to') ?? Carbon::now();

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'stations' => DB::table('v_station_utilisation')
                ->whereBetween('session_date', [$from->toDateString(), $to->toDateString()])
                ->orderBy('station_code')
                ->orderBy('session_date')
                ->get(),
        ]);
    }
}
