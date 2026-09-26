<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Adequacy;
use App\Domain\Clinical\Exceptions\CohortViolationException;
use App\Domain\Clinical\Exceptions\SessionLockedException;
use App\Domain\Clinical\Models\HdPrescription;
use App\Domain\Clinical\Models\SessionNote;
use App\Domain\Clinical\Models\TreatmentSession;
use App\Domain\Core\Models\Staff;
use App\Domain\Ops\Models\DialyzerUnit;
use App\Domain\Ops\Services\DialyzerReuseService;
use App\Domain\Ops\Services\MachineService;
use App\Domain\Ops\Services\WaterComplianceService;
use App\Support\Exceptions\DomainRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The lifecycle of one treatment: check in, start, end.
 *
 * The state machine is enforced here rather than left to whoever calls the API,
 * because the columns only make sense in order -- an `ended_at` on a session
 * that never started, or a post weight with no pre weight, produces generated
 * columns (weight_loss_kg, actual_duration_min) that are quietly wrong rather
 * than obviously missing.
 */
final class SessionService
{
    public function __construct(
        private readonly WaterComplianceService $water,
        private readonly DialyzerReuseService $reuse,
        private readonly MachineService $machines,
        private readonly CohortGuard $cohortGuard,
    ) {}

    /**
     * Infection-control breaches that were deliberately overridden on the last
     * checkIn() or start() call.
     *
     * Empty on any normal assignment. Non-empty means someone proceeded past
     * invariant 1 on purpose, and the reason is already on the chart.
     *
     * @var list<string>
     */
    public array $cohortOverrides = [];

    /**
     * Hygiene notes that do not block: how much has run on this machine since it
     * was last cleaned. The interval is unit policy, so this is reported rather
     * than enforced. Cohort carry-over is a different matter and does block.
     *
     * @var list<string>
     */
    public array $machineWarnings = [];

    /**
     * Which statuses each transition may be entered from.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        'checked_in' => ['scheduled'],
        'in_progress' => ['checked_in'],
        'completed' => ['in_progress'],
        'aborted' => ['in_progress'],
    ];

    /**
     * Check a patient in and pin down what this session is being run against.
     *
     * Two things are snapshotted onto the session here rather than looked up
     * later, and both are effective-dated:
     *
     *   dry_weight_kg    the target in force today. IDWG is a generated column
     *                    (pre_weight - dry_weight), so reading the dry weight
     *                    later would silently re-interpret this session's fluid
     *                    numbers the next time the target is revised.
     *   prescription_id  the version the treatment actually ran under, which is
     *                    the whole reason prescriptions are versioned.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function checkIn(TreatmentSession $session, array $attributes, Staff $actor): TreatmentSession
    {
        // Atomic for the same reason as start(): an override note must not
        // outlive a check-in that failed after it was written.
        return DB::transaction(fn (): TreatmentSession => $this->performCheckIn($session, $attributes, $actor));
    }

    /** @param  array<string, mixed>  $attributes */
    private function performCheckIn(TreatmentSession $session, array $attributes, Staff $actor): TreatmentSession
    {
        $this->assertTransition($session, 'checked_in');

        $prescription = $this->prescriptionFor($session);
        $dryWeight = $this->dryWeightFor($session);

        $this->assertMachineUsable($attributes['machine_id'] ?? $session->machine_id);

        // Invariant 1. Refused unless someone gives a reason, which goes on the chart.
        $this->assertInfectionControl(
            $session,
            $attributes['station_id'] ?? $session->station_id,
            $attributes['machine_id'] ?? $session->machine_id,
            $attributes['cohort_override_reason'] ?? null,
            $actor,
        );

        $values = array_intersect_key($attributes, array_flip([
            'pre_weight_kg', 'pre_bp_sys', 'pre_bp_dia', 'pre_bp_sys_standing', 'pre_bp_dia_standing',
            'pre_pulse', 'pre_temp_c', 'pre_resp_rate', 'pre_spo2_pct', 'pre_glucose_mmol',
            'pre_assessment', 'pre_notes', 'vascular_access_id', 'machine_id', 'station_id',
        ]));

        $session->forceFill($values + array_filter([
            'status' => 'checked_in',
            'checked_in_at' => Carbon::now(),
            'dry_weight_kg' => $dryWeight,
            'prescription_id' => $prescription?->id,
            'planned_duration_min' => $prescription?->duration_min,
            'primary_nurse_id' => $session->primary_nurse_id ?? $actor->id,
            'updated_by' => $actor->id,
        ], fn (mixed $value): bool => $value !== null))->save();

        $session->refresh();
        $this->raiseCohortWarnings($session);

        return $session;
    }

    /**
     * Needle in, pump on.
     *
     * The actual settings are recorded separately from the prescribed ones. What
     * was ordered and what was delivered are different questions, and adequacy
     * review needs both.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function start(TreatmentSession $session, array $attributes, Staff $actor): TreatmentSession
    {
        // One transaction for the whole step. The checks below are not all
        // read-only: an infection-control override writes its note to the chart
        // BEFORE the dialyzer is checked, and issueTo() stamps the unit before the
        // session is saved. Without this, overriding a chair and then being
        // refused a dialyzer left an "INFECTION CONTROL OVERRIDE" note on the
        // chart for a treatment that never started -- a record of an event that
        // did not happen.
        return DB::transaction(fn (): TreatmentSession => $this->performStart($session, $attributes, $actor));
    }

    /** @param  array<string, mixed>  $attributes */
    private function performStart(TreatmentSession $session, array $attributes, Staff $actor): TreatmentSession
    {
        $this->assertTransition($session, 'in_progress');

        if ($session->pre_weight_kg === null) {
            throw new DomainRuleException('Record the pre-dialysis weight before starting; the UF goal depends on it.');
        }

        // Invariant 9. Checked before the first needle of the day goes in, which
        // is the only moment at which it can still prevent anything.
        $this->water->assertClearedToStart($session->session_date);

        // A machine under repair, quarantined or retired is not available,
        // whatever the board says. This one does block.
        $this->assertMachineUsable($attributes['machine_id'] ?? $session->machine_id);

        // Invariant 1. Also checked at check-in, and again here because the chair
        // or machine may have changed in between.
        $this->assertInfectionControl(
            $session,
            $attributes['station_id'] ?? $session->station_id,
            $attributes['machine_id'] ?? $session->machine_id,
            $attributes['cohort_override_reason'] ?? null,
            $actor,
        );

        // Invariant 8. A dialyzer below 80% TCV, past its reuse count, or
        // belonging to another patient is refused at the point of issue.
        $unit = $this->dialyzerUnit($attributes);

        if ($unit !== null) {
            $patient = $session->patient;

            if ($patient === null) {
                throw new DomainRuleException('This session has no patient; a dialyzer cannot be issued.');
            }

            $this->reuse->assertIssuable($unit, $patient);
            $this->reuse->issueTo($unit, $session);
        }

        // issueTo() stamps the unit but not the session. When the unit was named
        // by its label -- the path a client actually has -- the session would
        // otherwise record no dialyzer at all, and a reuse audit could not say
        // which physical unit this treatment ran on. Taken from the unit rather
        // than the request, so the session cannot name a different unit or item
        // from the one that passed the checks above.
        $issued = $unit === null ? [] : [
            'dialyzer_unit_id' => $unit->id,
            'dialyzer_item_id' => $unit->item_id,
        ];

        $values = array_intersect_key($attributes, array_flip([
            'machine_id', 'dialyzer_item_id', 'dialyzer_unit_id', 'dialyzer_use_no',
            'bloodline_lot_id', 'vascular_access_id', 'needle_gauge', 'cannulation_attempts',
            'planned_duration_min', 'planned_uf_ml', 'blood_flow_set_ml_min',
            'dialysate_flow_ml_min', 'dialysate_na_mmol', 'dialysate_k_mmol', 'dialysate_ca_mmol',
            'dialysate_hco3_mmol', 'dialysate_temp_c', 'anticoagulant', 'ac_loading_dose',
            'ac_maintenance_hr', 'priming_volume_ml',
        ]));

        // $issued on the LEFT: `+` keeps the left operand on a key clash, so the
        // unit that passed invariant 8 wins over any id the request also sent.
        $session->forceFill($issued + $values + [
            'status' => 'in_progress',
            'started_at' => $attributes['started_at'] ?? Carbon::now(),
            'updated_by' => $actor->id,
        ])->save();

        $session->refresh();
        $this->raiseCohortWarnings($session);

        return $session;
    }

    /**
     * Off the machine.
     *
     * A treatment stopped early is `aborted`, not `completed`, and it carries a
     * termination_reason. Recording a short run as completed would make the
     * monthly adequacy numbers look better than the unit actually performed.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function end(TreatmentSession $session, array $attributes, Staff $actor): TreatmentSession
    {
        $reason = (string) ($attributes['termination_reason'] ?? 'completed_as_prescribed');
        $status = $reason === 'completed_as_prescribed' ? 'completed' : 'aborted';

        $this->assertTransition($session, $status);

        $values = array_intersect_key($attributes, array_flip([
            'post_weight_kg', 'net_uf_ml', 'total_intake_ml', 'post_bp_sys', 'post_bp_dia',
            'post_bp_sys_standing', 'post_bp_dia_standing', 'post_pulse', 'post_temp_c',
            'post_resp_rate', 'post_spo2_pct', 'blood_volume_processed_l',
            'ktv', 'ktv_method', 'urr_pct', 'pre_bun_mmol', 'post_bun_mmol',
            'ambulation', 'discharge_condition', 'discharge_notes', 'termination_notes',
            'ac_total_given',
        ]));

        $endedAt = $attributes['ended_at'] ?? Carbon::now();

        // Derived values first: array + keeps the LEFT operand on a key clash, so
        // the server's recomputed Kt/V overrides whatever the client sent rather
        // than the other way round.
        $session->forceFill($this->derivedAdequacy($session, $values, $endedAt) + $values + [
            'status' => $status,
            'ended_at' => $endedAt,
            'termination_reason' => $reason,
            'discharged_at' => $attributes['discharged_at'] ?? Carbon::now(),
            'updated_by' => $actor->id,
        ])->save();

        return $session->refresh();
    }

    /**
     * Recompute adequacy from the raw measurements rather than trusting it.
     *
     * Kt/V and URR are derived values. A client that sends one is sending an
     * answer nobody has checked, and a chart holding a Kt/V inconsistent with its
     * own BUN samples is a chart that will be believed and is wrong. The bedside
     * tablet still computes them so a nurse sees a result immediately -- see
     * packages/domain -- but the server's number is the one that is stored.
     *
     * With one deliberate exception. `online_clearance` and `ionic` Kt/V come off
     * the machine's own dialysance sensor; they are measurements, not derivations
     * from BUN, and recomputing over them would replace a real reading with an
     * estimate. `equilibrated` is a different equation again (eKt/V), which this
     * codebase has not been given. All three are left as sent.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function derivedAdequacy(TreatmentSession $session, array $values, mixed $endedAt): array
    {
        $preBun = $values['pre_bun_mmol'] ?? $session->pre_bun_mmol;
        $postBun = $values['post_bun_mmol'] ?? $session->post_bun_mmol;

        if ($preBun === null || $postBun === null) {
            return [];
        }

        $derived = ['urr_pct' => Adequacy::urrPct((float) $preBun, (float) $postBun)];

        $method = $values['ktv_method'] ?? $session->ktv_method ?? 'single_pool_daugirdas';

        if ($method !== 'single_pool_daugirdas') {
            return $derived;
        }

        $startedAt = $session->started_at;
        $postWeight = $values['post_weight_kg'] ?? $session->post_weight_kg;
        $netUfMl = $values['net_uf_ml'] ?? $session->net_uf_ml;

        if ($startedAt === null || $postWeight === null || $netUfMl === null) {
            return $derived;
        }

        $hours = $startedAt->diffInMinutes(Carbon::parse((string) $endedAt)) / 60;

        if ($hours <= 0) {
            return $derived;
        }

        $derived['ktv'] = Adequacy::ktv(
            preBun: (float) $preBun,
            postBun: (float) $postBun,
            hours: $hours,
            ufLitres: (float) $netUfMl / 1000,
            postWeightKg: (float) $postWeight,
        );
        $derived['ktv_method'] = 'single_pool_daugirdas';

        return $derived;
    }

    /** A machine that is under repair, quarantined or retired may not be used. */
    private function assertMachineUsable(mixed $machineId): void
    {
        if ($machineId === null) {
            return;
        }

        $this->machines->assertUsable((int) $machineId);
    }

    /**
     * Refuse an assignment that breaches infection-control segregation.
     *
     * Invariant 1, enforced rather than reported. Three things breach it: a chair
     * designated for another cohort, a machine dedicated to one, and a machine
     * that last treated a different cohort with no disinfection cycle since.
     *
     * An explicit reason overrides it, because a unit with its only HBV chair
     * broken and a patient who needs dialysing today has to be able to proceed.
     * The override goes on the chart and into audit_logs. A rule with no override
     * gets worked around outside the system, where nothing records it at all.
     *
     * v_cohort_violation stays the backstop for anything that never comes through
     * this path -- a console command, an import, a stray script.
     *
     * @throws CohortViolationException
     */
    private function assertInfectionControl(
        TreatmentSession $session,
        mixed $stationId,
        mixed $machineId,
        mixed $overrideReason,
        Staff $actor,
    ): void {
        $this->cohortOverrides = [];

        $patient = $session->patient;

        if ($patient === null) {
            return;
        }

        $violations = $this->cohortGuard->violations(
            $patient,
            $stationId === null ? null : (int) $stationId,
            $machineId === null ? null : (int) $machineId,
        );

        if ($machineId !== null) {
            $carried = $this->machines->cohortCarriedOver((int) $machineId);

            if ($carried !== null && $carried !== $patient->cohort()->value) {
                $violations[] = sprintf(
                    'That machine last treated a %s patient and has had no disinfection cycle since.',
                    $carried,
                );
            }
        }

        if ($violations === []) {
            return;
        }

        $reason = is_string($overrideReason) ? trim($overrideReason) : '';

        if ($reason === '') {
            throw new CohortViolationException($violations);
        }

        $this->cohortOverrides = $violations;
        $this->recordCohortOverride($session, $violations, $reason, $actor);
    }

    /**
     * Put the override on the chart.
     *
     * A session note rather than only a log line: this belongs where a clinician
     * reviewing the record will actually see it, next to the amendment notes.
     *
     * @param  list<string>  $violations
     */
    private function recordCohortOverride(
        TreatmentSession $session,
        array $violations,
        string $reason,
        Staff $actor,
    ): void {
        SessionNote::create([
            'session_id' => $session->id,
            'note_type' => 'nursing',
            'body' => sprintf(
                "INFECTION CONTROL OVERRIDE by %s: %s\nBreached: %s",
                $actor->full_name,
                $reason,
                implode(' ', $violations),
            ),
            'author_id' => $actor->id,
        ]);
    }

    /** Hygiene notes that do not block, raised after a successful assignment. */
    private function raiseCohortWarnings(TreatmentSession $session): void
    {
        $this->machineWarnings = $session->machine_id === null
            ? []
            : $this->machines->hygieneWarnings((int) $session->machine_id);
    }

    /**
     * The dialyzer unit named in a start payload, if one was named.
     *
     * By label first: that is what is printed on the unit and scanned at the
     * chair, and it is the only identifier a client is ever given. Looked up
     * across every patient deliberately -- a label belonging to someone else
     * must reach assertIssuable() and be refused as invariant 8 ("belongs to a
     * different patient"), not vanish as a lookup miss.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function dialyzerUnit(array $attributes): ?DialyzerUnit
    {
        $label = $attributes['dialyzer_label_code'] ?? null;

        if (is_string($label) && trim($label) !== '') {
            return DialyzerUnit::query()->where('label_code', trim($label))->first();
        }

        $id = $attributes['dialyzer_unit_id'] ?? null;

        return $id === null ? null : DialyzerUnit::query()->whereKey((int) $id)->first();
    }

    /** The prescription version in force on this session's date. */
    public function prescriptionFor(TreatmentSession $session): ?HdPrescription
    {
        return HdPrescription::query()
            ->where('patient_id', $session->patient_id)
            ->where('effective_from', '<=', $session->session_date)
            ->where('effective_to_x', '>', $session->session_date)
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * The dry weight in force on the session date.
     *
     * Read straight from dry_weights rather than v_current_dry_weight, because
     * that view is anchored to CURDATE() and this session may be backdated.
     */
    public function dryWeightFor(TreatmentSession $session): ?string
    {
        $weight = DB::table('dry_weights')
            ->where('patient_id', $session->patient_id)
            ->where('effective_from', '<=', $session->session_date)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->value('weight_kg');

        return $weight === null ? null : (string) $weight;
    }

    private function assertTransition(TreatmentSession $session, string $to): void
    {
        if ($session->isLocked()) {
            throw new SessionLockedException($session);
        }

        $from = $session->status->value;

        if (! in_array($from, self::TRANSITIONS[$to] ?? [], true)) {
            throw new DomainRuleException(
                "A session that is {$from} cannot become {$to}."
                .' Expected one of: '.implode(', ', self::TRANSITIONS[$to] ?? []).'.'
            );
        }
    }
}
