<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Exceptions\DuplicateObservationException;
use App\Domain\Clinical\Exceptions\SessionLockedException;
use App\Domain\Clinical\Models\MedicationAdministration;
use App\Domain\Clinical\Models\SessionEvent;
use App\Domain\Clinical\Models\SessionVital;
use App\Domain\Clinical\Models\TreatmentSession;
use App\Domain\Core\Models\Staff;
use App\Support\Exceptions\DomainRuleException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Everything written onto a running flow sheet.
 *
 * This is the single implementation. The bedside tablet reaches it through
 * SyncBatchProcessor when it comes back online, and an online client reaches it
 * through the HTTP endpoints -- but both take this path, so a vital charted
 * offline and a vital charted at the desk cannot end up following different
 * rules. Two implementations of "append a vital" would drift, and the drift
 * would show up as a flow sheet that reads differently depending on which
 * device wrote it.
 */
final class FlowSheetService
{
    /**
     * Columns a client may set on an observation. Anything else it sends is
     * ignored rather than trusted -- recorded_by and session_id are the
     * server's to decide.
     *
     * @var list<string>
     */
    public const VITAL_COLUMNS = [
        'recorded_at', 'minutes_elapsed', 'bp_sys', 'bp_dia', 'pulse', 'temp_c',
        'resp_rate', 'spo2_pct', 'blood_flow_ml_min', 'arterial_pressure_mmhg',
        'venous_pressure_mmhg', 'tmp_mmhg', 'dialysate_flow_ml_min',
        'conductivity_ms_cm', 'dialysate_temp_c', 'uf_rate_ml_hr', 'uf_volume_ml',
        'rbv_pct', 'heparin_given', 'comment', 'source',
    ];

    /** @var list<string> */
    public const EVENT_COLUMNS = [
        'occurred_at', 'event_code', 'severity', 'description', 'intervention', 'outcome',
    ];

    /** @var list<string> */
    public const MEDICATION_COLUMNS = [
        'medication_id', 'order_id', 'dose', 'dose_unit', 'route', 'administered_at',
        'timing', 'lot_id', 'site', 'witnessed_by', 'not_given', 'not_given_reason', 'reaction',
    ];

    /**
     * Append an intra-dialytic observation.
     *
     * Append-only and keyed by (session_id, recorded_at), which is what makes
     * these conflict-free: two devices charting the same session cannot produce
     * a merge conflict, only a duplicate, and the unique index turns that into a
     * no-op. There is deliberately no update path -- see CLAUDE.md.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws SessionLockedException
     * @throws DuplicateObservationException
     */
    public function appendVital(
        TreatmentSession $session,
        array $attributes,
        Staff $actor,
        ?string $clientUuid = null,
    ): SessionVital {
        $this->assertOpen($session);

        $values = array_intersect_key($attributes, array_flip(self::VITAL_COLUMNS));

        try {
            return SessionVital::create($values + [
                'session_id' => $session->id,
                'client_uuid' => $clientUuid,
                'source' => $values['source'] ?? 'manual',
                'recorded_by' => $actor->id,
            ]);
        } catch (QueryException $e) {
            // 1062: either the same client_uuid or the same (session, recorded_at).
            // Both mean the server already has this reading.
            if ($this->isDuplicate($e)) {
                throw new DuplicateObservationException(
                    'An observation for this session at that time is already recorded.',
                    previous: $e,
                );
            }

            throw $e;
        }
    }

    /**
     * Record an intra-dialytic event -- a hypotensive episode, cramps, a
     * clotted circuit.
     *
     * event_code is a foreign key into event_refs, so a typo is rejected rather
     * than quietly creating a category nothing reports on.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws SessionLockedException
     * @throws DuplicateObservationException when the same client_uuid is replayed
     */
    public function appendEvent(
        TreatmentSession $session,
        array $attributes,
        Staff $actor,
        ?string $clientUuid = null,
    ): SessionEvent {
        $this->assertOpen($session);

        $values = array_intersect_key($attributes, array_flip(self::EVENT_COLUMNS));

        try {
            return SessionEvent::create($values + [
                'session_id' => $session->id,
                'client_uuid' => $clientUuid,
                'occurred_at' => $values['occurred_at'] ?? Carbon::now(),
                'reported_by' => $actor->id,
            ]);
        } catch (QueryException $e) {
            if ($this->isDuplicate($e)) {
                throw new DuplicateObservationException('This event is already recorded.', previous: $e);
            }

            throw $e;
        }
    }

    /**
     * Record a medication administration.
     *
     * Invariant 7: a high-alert drug requires a witness. The
     * medication_administrations_bi trigger is the backstop; this checks first
     * so the nurse is told *which* drug needs a witness rather than being handed
     * a constraint violation.
     *
     * It also refuses a self-witnessed high-alert dose. The database only
     * requires witnessed_by to be non-null, but a nurse who witnesses their own
     * administration is not a second check -- which is the entire point of the
     * rule. That refusal is a decision made here, at the service layer, and it
     * is stricter than the schema.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws SessionLockedException
     */
    public function administer(
        TreatmentSession $session,
        array $attributes,
        Staff $actor,
        ?string $clientUuid = null,
    ): MedicationAdministration {
        $this->assertOpen($session);

        $values = array_intersect_key($attributes, array_flip(self::MEDICATION_COLUMNS));

        $notGiven = (bool) ($values['not_given'] ?? false);
        $witness = $values['witnessed_by'] ?? null;

        if (! $notGiven && $this->isHighAlert((int) $values['medication_id'])) {
            $name = $this->medicationName((int) $values['medication_id']);

            if ($witness === null) {
                throw new DomainRuleException("{$name} is a high-alert medication and requires a witness.");
            }

            if ((int) $witness === $actor->id) {
                throw new DomainRuleException(
                    "{$name} requires a second person to witness it; the nurse giving it cannot witness their own dose."
                );
            }
        }

        try {
            return MedicationAdministration::create($values + [
                'session_id' => $session->id,
                'patient_id' => $session->patient_id,
                'client_uuid' => $clientUuid,
                'administered_at' => $values['administered_at'] ?? Carbon::now(),
                'given_by' => $actor->id,
            ]);
        } catch (QueryException $e) {
            if ($this->isDuplicate($e)) {
                throw new DuplicateObservationException('This administration is already recorded.', previous: $e);
            }

            throw $e;
        }
    }

    /** Nothing may be charted onto a signed record; it goes through the amendment path. */
    private function assertOpen(TreatmentSession $session): void
    {
        if ($session->isLocked()) {
            throw new SessionLockedException($session);
        }
    }

    private function isDuplicate(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }

    private function isHighAlert(int $medicationId): bool
    {
        return DB::table('medication_refs')
            ->where('id', $medicationId)
            ->where('is_high_alert', 1)
            ->exists();
    }

    private function medicationName(int $medicationId): string
    {
        return (string) (DB::table('medication_refs')->where('id', $medicationId)->value('generic_name') ?? 'This medication');
    }
}
