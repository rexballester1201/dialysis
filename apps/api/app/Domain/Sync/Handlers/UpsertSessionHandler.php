<?php

declare(strict_types=1);

namespace App\Domain\Sync\Handlers;

use App\Domain\Clinical\Models\TreatmentSession;
use App\Domain\Core\Models\Staff;

/**
 * The session header, charted from a tablet that may have been offline:
 * observations and the settings actually delivered.
 *
 * Ownership model, not field-level merge: while a session is open, exactly one
 * device is charting it, so last-write-wins is safe for the header. The cases
 * that genuinely happen -- the record being signed or ended on another device
 * while this one was offline -- come back as a conflict rather than silently
 * overwriting.
 *
 * WHAT THIS HANDLER WILL NOT DO
 * -----------------------------
 * It does not move a session through its lifecycle, issue a dialyzer, store a
 * derived value, change the snapshotted dry weight, or reassign who did the
 * work. An earlier version accepted all of those through forceFill with no
 * checks at all, which made this a side door around invariants 1, 8 and 9:
 * a payload carrying `status: in_progress` started a treatment without the
 * water check, the cohort check, the machine check or the dialyzer check. It
 * also stored a client's Kt/V and URR as sent, which the prime directive
 * forbids.
 *
 * Those transitions go through SessionService online, because the refusals they
 * carry are only useful before the needle goes in -- a cohort breach reported
 * at sync time, hours later, arrives after the patient has been dialysed.
 *
 * An operation that carries one of those fields is REJECTED, naming them, not
 * quietly trimmed. Dropping a `status` in silence would let a tablet believe it
 * had started a session it had not.
 */
final class UpsertSessionHandler implements OperationHandler
{
    /** Observations and delivered settings -- nothing here crosses an invariant. */
    private const ALLOWED = [
        // Pre-treatment observations.
        'pre_weight_kg', 'pre_bp_sys', 'pre_bp_dia', 'pre_bp_sys_standing', 'pre_bp_dia_standing',
        'pre_pulse', 'pre_temp_c', 'pre_resp_rate', 'pre_spo2_pct', 'pre_glucose_mmol',
        'pre_assessment', 'pre_notes',
        // Access and cannulation.
        'vascular_access_id', 'needle_gauge', 'cannulation_attempts',
        // Settings actually delivered.
        'planned_duration_min', 'planned_uf_ml', 'blood_flow_set_ml_min', 'dialysate_flow_ml_min',
        'dialysate_na_mmol', 'dialysate_k_mmol', 'dialysate_ca_mmol', 'dialysate_hco3_mmol',
        'dialysate_temp_c', 'anticoagulant', 'ac_loading_dose', 'ac_maintenance_hr', 'ac_total_given',
        'priming_volume_ml', 'bloodline_lot_id',
        // Post-treatment observations, which a nurse may take before the end is
        // recorded online. end() derives adequacy from whatever is here then.
        'post_weight_kg', 'net_uf_ml', 'total_intake_ml', 'post_bp_sys', 'post_bp_dia',
        'post_pulse', 'post_temp_c', 'post_resp_rate', 'post_spo2_pct', 'blood_volume_processed_l',
        'pre_bun_mmol', 'post_bun_mmol',
        // Discharge.
        'ambulation', 'discharge_condition', 'discharge_notes', 'termination_notes',
    ];

    /**
     * Fields that must only change through the checked, online path.
     *
     * Grouped by why, because the rejection message names the group a nurse or
     * a developer needs to understand.
     *
     * @var array<string, list<string>>
     */
    private const REFUSED = [
        'lifecycle changes (check in, start and end need a connection)' => [
            'status', 'checked_in_at', 'started_at', 'ended_at', 'discharged_at', 'termination_reason',
        ],
        'chair, machine or prescription changes (infection control is checked online)' => [
            'station_id', 'machine_id', 'prescription_id',
        ],
        'dialyzer issue (checked against reuse limits when the session is started)' => [
            'dialyzer_item_id', 'dialyzer_unit_id', 'dialyzer_use_no',
        ],
        'adequacy values (the server calculates these from the samples)' => [
            'ktv', 'ktv_method', 'urr_pct',
        ],
        'the dry weight (it is taken from the patient record at check-in)' => [
            'dry_weight_kg',
        ],
        'signatures and attribution' => [
            'primary_nurse_id', 'assisting_staff_id', 'technician_id',
            'nurse_signed_at', 'physician_signed_at', 'locked_at',
        ],
    ];

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    public function handle(array $operation, Staff $actor, string $opUuid): array
    {
        $payload = $operation['payload'] ?? [];

        $refused = $this->refusedIn($payload);

        if ($refused !== []) {
            return [
                'op_uuid' => $opUuid,
                'status' => 'rejected',
                'message' => 'Not applied. An offline update cannot change '
                    .implode('; ', array_keys($refused)).'. Refused: '
                    .implode(', ', array_merge(...array_values($refused))).'.',
            ];
        }

        $session = TreatmentSession::where('public_id', $payload['session_public_id'] ?? '')->first();

        if ($session === null) {
            return ['op_uuid' => $opUuid, 'status' => 'rejected', 'message' => 'Unknown session'];
        }

        if ($session->isLocked()) {
            return [
                'op_uuid' => $opUuid,
                'status' => 'conflict',
                'message' => 'Session was signed on another device. Your edits were not applied; '
                            .'submit them as an amendment if they are still correct.',
                'server_state' => $session->only([
                    'status', 'locked_at', 'pre_weight_kg', 'post_weight_kg', 'net_uf_ml', 'ktv',
                ]),
            ];
        }

        // Ended but not yet signed. end() derived Kt/V and URR from the weights
        // and samples present at that moment; changing them now would leave a
        // stored adequacy that disagrees with its own inputs. The online path
        // offers no edit between end and signature either -- after signing,
        // corrections go through the amendment path.
        if (in_array($session->status->value, ['completed', 'aborted'], true)) {
            return [
                'op_uuid' => $opUuid,
                'status' => 'conflict',
                'message' => 'This treatment was ended while this device was offline. Its adequacy was '
                            .'calculated from the values recorded then, so these edits were not applied. '
                            .'Once it is signed, correct it through the amendment path.',
                'server_state' => $session->only(['status', 'ended_at', 'post_weight_kg', 'net_uf_ml', 'ktv', 'urr_pct']),
            ];
        }

        $session->forceFill(
            array_intersect_key($payload, array_flip(self::ALLOWED)) + [
                'device_id' => $operation['device_id'] ?? null,
                'synced_at' => now(),
                'updated_by' => $actor->id,
            ]
        )->save();

        return ['op_uuid' => $opUuid, 'status' => 'applied', 'server_id' => $session->id];
    }

    /**
     * The refused fields present in a payload, grouped by why.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, list<string>>
     */
    private function refusedIn(array $payload): array
    {
        $found = [];

        foreach (self::REFUSED as $why => $fields) {
            $present = array_values(array_filter($fields, fn (string $f): bool => array_key_exists($f, $payload)));

            if ($present !== []) {
                $found[$why] = $present;
            }
        }

        return $found;
    }
}
