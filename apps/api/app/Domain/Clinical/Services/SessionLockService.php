<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Models\SessionNote;
use App\Domain\Clinical\Models\TreatmentSession;
use App\Domain\Core\Models\Staff;
use App\Support\Exceptions\DomainRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Owns the sign-off / lock / amendment lifecycle of a treatment record.
 *
 * The database enforces the same rule via the treatment_sessions_bu trigger.
 * That duplication is deliberate: this class produces good error messages and
 * audit entries; the trigger stops a console command or a stray script.
 */
final class SessionLockService
{
    /** Columns a locked record still accepts, because claims are attached after sign-off. */
    public const ADMIN_COLUMNS = ['benefit_claim_id', 'is_billable', 'updated_at', 'updated_by'];

    public function signAsNurse(TreatmentSession $session, Staff $nurse): TreatmentSession
    {
        $this->assertUnlocked($session);
        $this->assertComplete($session);

        $session->forceFill([
            'nurse_signed_by' => $nurse->id,
            'nurse_signed_at' => now(),
            'updated_by' => $nurse->id,
        ])->save();

        return $this->lockIfFullySigned($session);
    }

    public function signAsPhysician(TreatmentSession $session, Staff $physician): TreatmentSession
    {
        $this->assertUnlocked($session);

        $session->forceFill([
            'physician_signed_by' => $physician->id,
            'physician_signed_at' => now(),
            'updated_by' => $physician->id,
        ])->save();

        return $this->lockIfFullySigned($session);
    }

    /**
     * The only sanctioned way to change a signed record. The original values
     * stay in place; the correction is a new row in session_notes and a full
     * before/after pair in audit_logs.
     */
    /** @param  array<string, mixed>  $changes */
    public function amend(
        TreatmentSession $session,
        array $changes,
        string $reason,
        Staff $actor,
    ): TreatmentSession {
        if (! $session->isLocked()) {
            throw new DomainRuleException('Session is not locked; edit it normally.');
        }
        if (trim($reason) === '') {
            throw new DomainRuleException('An amendment requires a reason.');
        }
        if ($illegal = array_intersect(array_keys($changes), ['id', 'public_id', 'patient_id', 'locked_at'])) {
            throw new DomainRuleException('Cannot amend: '.implode(', ', $illegal));
        }

        return DB::transaction(function () use ($session, $changes, $reason, $actor) {
            $before = $session->only(array_keys($changes));

            // Lifts the MySQL trigger for this connection only, inside this
            // transaction. Mirrors SET app.allow_amendment = 'on' in Postgres.
            DB::statement('SET @allow_amendment = 1');

            try {
                $session->forceFill($changes + ['updated_by' => $actor->id])->save();

                SessionNote::create([
                    'session_id' => $session->id,
                    'note_type' => 'nursing',
                    'body' => sprintf(
                        "AMENDMENT by %s: %s\nBefore: %s\nAfter: %s",
                        $actor->full_name,
                        $reason,
                        json_encode($before, JSON_THROW_ON_ERROR),
                        json_encode($changes, JSON_THROW_ON_ERROR),
                    ),
                    'author_id' => $actor->id,
                ]);
            } finally {
                DB::statement('SET @allow_amendment = 0');
            }

            return $session->refresh();
        });
    }

    private function lockIfFullySigned(TreatmentSession $session): TreatmentSession
    {
        if ($session->isFullySigned() && ! $session->isLocked()) {
            $session->forceFill(['locked_at' => now()])->save();
        }

        return $session->refresh();
    }

    private function assertUnlocked(TreatmentSession $session): void
    {
        if ($session->isLocked()) {
            throw new DomainRuleException("Session {$session->public_id} is already locked.");
        }
    }

    /** A record cannot be signed with the clinically mandatory fields missing. */
    private function assertComplete(TreatmentSession $session): void
    {
        $required = ['pre_weight_kg', 'post_weight_kg', 'started_at', 'ended_at', 'primary_nurse_id'];
        $missing = array_values(array_filter($required, fn ($f) => $session->{$f} === null));

        if ($missing !== []) {
            throw new DomainRuleException('Cannot sign, missing: '.implode(', ', $missing));
        }
    }
}
