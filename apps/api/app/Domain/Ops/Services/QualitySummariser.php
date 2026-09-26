<?php

declare(strict_types=1);

namespace App\Domain\Ops\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the dashboard's monthly rollup from v_monthly_quality.
 *
 * The view stays the definition of record (CLAUDE.md, MySQL rule 7). This copies
 * its numbers into a table the dashboard can query by patient and month without
 * materialising four years of sessions first.
 *
 * The summary is only ever written from the view, never computed independently.
 * A second implementation of "average Kt/V this month" would eventually disagree
 * with the first, and the one on the screen is the one people would act on.
 */
final class QualitySummariser
{
    /**
     * Rebuild one month.
     *
     * Deletes and reinserts rather than upserting row by row: a patient whose
     * only session that month was later cancelled should disappear from the
     * summary, and an upsert would leave the stale row behind.
     *
     * @return int rows written
     */
    public function summariseMonth(CarbonInterface $anyDayInMonth): int
    {
        $month = Carbon::parse($anyDayInMonth->toDateString())->startOfMonth()->toDateString();
        $now = Carbon::now();

        return DB::transaction(function () use ($month, $now): int {
            DB::table('monthly_quality_summaries')->where('month', $month)->delete();

            $rows = [];

            foreach (DB::table('v_monthly_quality')->where('month', $month)->get() as $row) {
                $rows[] = [
                    'patient_id' => $row->patient_id,
                    'month' => $month,
                    'sessions' => (int) $row->sessions,
                    'completed' => (int) $row->completed,
                    'missed' => (int) $row->missed,
                    'shortened' => (int) $row->shortened,
                    'sessions_with_hypotension' => (int) ($row->sessions_with_hypotension ?? 0),
                    'sessions_with_reportable_event' => (int) ($row->sessions_with_reportable_event ?? 0),
                    'avg_ktv' => $row->avg_ktv,
                    'avg_urr' => $row->avg_urr,
                    'avg_idwg_kg' => $row->avg_idwg_kg,
                    'avg_duration_min' => $row->avg_duration_min,
                    'avg_pre_sbp' => $row->avg_pre_sbp,
                    'summarised_at' => $now,
                ];
            }

            if ($rows !== []) {
                DB::table('monthly_quality_summaries')->insert($rows);
            }

            return count($rows);
        });
    }

    /**
     * Rebuild the current month and the one before it.
     *
     * Two months because a late signature, an amendment or a backdated session
     * can change a month after it has ended -- and a rollup that only ever
     * touches the current month would keep yesterday's answer for that.
     *
     * @return array<string, int>
     */
    public function summariseRecent(?CarbonInterface $asOf = null): array
    {
        $at = Carbon::parse(($asOf ?? Carbon::now())->toDateString());

        return [
            $at->copy()->subMonthNoOverflow()->startOfMonth()->toDateString() => $this->summariseMonth($at->copy()->subMonthNoOverflow()),
            $at->copy()->startOfMonth()->toDateString() => $this->summariseMonth($at),
        ];
    }

    /**
     * When the summary was last rebuilt, or null if it never has been.
     *
     * The dashboard shows this. A screen that cannot say how stale it is invites
     * someone to trust it further than they should.
     */
    public function lastSummarisedAt(): ?string
    {
        $at = DB::table('monthly_quality_summaries')->max('summarised_at');

        return $at === null ? null : Carbon::parse((string) $at)->toIso8601String();
    }
}
