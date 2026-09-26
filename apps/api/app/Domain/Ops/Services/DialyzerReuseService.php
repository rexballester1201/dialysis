<?php

declare(strict_types=1);

namespace App\Domain\Ops\Services;

use App\Domain\Clinical\Models\TreatmentSession;
use App\Domain\Core\Models\Patient;
use App\Domain\Core\Models\Staff;
use App\Domain\Ops\Models\DialyzerUnit;
use App\Support\Exceptions\DomainRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Invariant 8: a reused dialyzer below 80% of its original total cell volume,
 * or past its reuse count, cannot be issued.
 *
 * Reprocessing a patient's own dialyzer is legal and routine here. Two things
 * make it safe, and both are checked at the moment of issue rather than reviewed
 * afterwards:
 *
 *   TCV >= 80%   below that the fibre bundle has clotted off far enough that
 *                clearance is no longer what was prescribed -- the patient is
 *                dialysed less than the chart claims.
 *   the unit is  a dialyzer carries the patient's blood proteins. Issuing one to
 *   this patient's a second patient is a cross-infection event, not an error to
 *                reconcile later.
 *
 * The 80% figure is the repo's own: CLAUDE.md ("Below 80% of new -> discard"),
 * the schema comment on dialyzer_units, and the v_dialyzer_status flag logic. It
 * is defined once here so the preventive check and the detective view agree.
 *
 * v_dialyzer_status stays the detective control. This service is the preventive
 * half that was missing.
 */
final class DialyzerReuseService
{
    /**
     * Minimum acceptable total cell volume, as a percentage of new.
     *
     * Source: CLAUDE.md domain glossary and v_dialyzer_status in
     * database/schema/mysql-schema.sql, which both use 80.
     */
    public const MIN_TCV_PCT = 80.0;

    /**
     * Why this unit may not be issued to this patient, or null if it may be.
     *
     * Returns rather than throws so a UI can grey out a barcode before the nurse
     * scans it, using the same logic that would refuse it afterwards.
     */
    public function refusalReason(DialyzerUnit $unit, Patient $patient): ?string
    {
        if ($unit->patient_id !== $patient->id) {
            $owner = DB::table('patients')->where('id', $unit->patient_id)->value('mrn');

            return "Dialyzer {$unit->label_code} belongs to {$owner} and must never be used on another patient.";
        }

        if ($unit->status !== 'active') {
            return "Dialyzer {$unit->label_code} is {$unit->status} and cannot be issued.";
        }

        $tcvPct = $this->tcvPercent($unit);

        if ($tcvPct !== null && $tcvPct < self::MIN_TCV_PCT) {
            return sprintf(
                'Dialyzer %s is at %s%% of its original total cell volume, below the %s%% minimum. Discard it.',
                $unit->label_code,
                $tcvPct,
                self::MIN_TCV_PCT,
            );
        }

        $maxReuse = $this->maxReuseCount($unit);

        if ($maxReuse !== null && (int) $unit->use_count >= $maxReuse) {
            return sprintf(
                'Dialyzer %s has been used %d times and its limit is %d. Discard it.',
                $unit->label_code,
                (int) $unit->use_count,
                $maxReuse,
            );
        }

        return null;
    }

    /**
     * Issue a unit to a session, or refuse.
     *
     * @throws DomainRuleException
     */
    public function assertIssuable(DialyzerUnit $unit, Patient $patient): void
    {
        $reason = $this->refusalReason($unit, $patient);

        if ($reason !== null) {
            throw new DomainRuleException($reason);
        }
    }

    /**
     * Record a reprocessing cycle.
     *
     * The unit is condemned here rather than left for a report when the measured
     * volume falls below the minimum or the count is spent. A dialyzer that
     * failed its pressure test, its fibre-bundle check or its residual germicide
     * test is condemned regardless of volume -- `accepted` is the technician's
     * verdict and it is final.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function reprocess(DialyzerUnit $unit, array $attributes, Staff $actor): DialyzerUnit
    {
        return DB::transaction(function () use ($unit, $attributes, $actor): DialyzerUnit {
            $useNumber = (int) $unit->use_count + 1;
            $tcvMl = $attributes['tcv_ml'] ?? null;
            $accepted = (bool) ($attributes['accepted'] ?? true);

            $tcvPct = null;

            if ($tcvMl !== null && $unit->initial_tcv_ml !== null && (float) $unit->initial_tcv_ml > 0) {
                $tcvPct = round((float) $tcvMl / (float) $unit->initial_tcv_ml * 100, 2);
            }

            DB::table('dialyzer_reprocess_logs')->insert([
                'dialyzer_unit_id' => $unit->id,
                'session_id' => $attributes['session_id'] ?? null,
                'reprocessed_at' => $attributes['reprocessed_at'] ?? Carbon::now(),
                'use_number' => $useNumber,
                'method' => $attributes['method'] ?? null,
                'germicide' => $attributes['germicide'] ?? null,
                'germicide_conc' => $attributes['germicide_conc'] ?? null,
                'tcv_ml' => $tcvMl,
                'tcv_pct_of_initial' => $tcvPct,
                'pressure_test_passed' => $attributes['pressure_test_passed'] ?? null,
                'fibre_bundle_ok' => $attributes['fibre_bundle_ok'] ?? null,
                'visual_ok' => $attributes['visual_ok'] ?? null,
                'residual_test_passed' => $attributes['residual_test_passed'] ?? null,
                'accepted' => $accepted,
                'reject_reason' => $attributes['reject_reason'] ?? null,
                'performed_by' => $actor->id,
                'created_at' => Carbon::now(),
            ]);

            $unit->use_count = $useNumber;

            if ($tcvMl !== null) {
                $unit->current_tcv_ml = $tcvMl;
            }

            $unit->save();
            // tcv_pct is generated; read it back before deciding on it.
            $unit->refresh();

            $condemnation = $this->condemnationReason($unit, $accepted, $attributes);

            if ($condemnation !== null) {
                $unit->status = 'discarded';
                $unit->discarded_on = Carbon::now();
                $unit->discard_reason = $condemnation;
                $unit->save();
            }

            return $unit->refresh();
        });
    }

    /**
     * Why this unit is finished, or null if it may go round again.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function condemnationReason(DialyzerUnit $unit, bool $accepted, array $attributes): ?string
    {
        if (! $accepted) {
            return (string) ($attributes['reject_reason'] ?? 'Rejected at reprocessing.');
        }

        $tcvPct = $this->tcvPercent($unit);

        if ($tcvPct !== null && $tcvPct < self::MIN_TCV_PCT) {
            return sprintf('Total cell volume %s%% is below the %s%% minimum.', $tcvPct, self::MIN_TCV_PCT);
        }

        $maxReuse = $this->maxReuseCount($unit);

        if ($maxReuse !== null && (int) $unit->use_count >= $maxReuse) {
            return sprintf('Reuse limit of %d reached.', $maxReuse);
        }

        return null;
    }

    /**
     * Total cell volume as a percentage of new, or null.
     *
     * Genuinely nullable: the generated column evaluates to NULL when no initial
     * volume was ever measured, and a unit nobody measured is not a unit known to
     * be within limits. Read through getAttribute() so the nullability survives.
     */
    public function tcvPercent(DialyzerUnit $unit): ?float
    {
        $value = $unit->getAttribute('tcv_pct');

        return $value === null ? null : (float) $value;
    }

    /** The reuse ceiling for this unit's item, if the item sets one. */
    public function maxReuseCount(DialyzerUnit $unit): ?int
    {
        $max = DB::table('items')->where('id', $unit->item_id)->value('max_reuse_count');

        return $max === null ? null : (int) $max;
    }

    /** Link an issued unit to the session that used it. */
    public function issueTo(DialyzerUnit $unit, TreatmentSession $session): void
    {
        $unit->first_used_on ??= $session->session_date;
        $unit->save();
    }
}
