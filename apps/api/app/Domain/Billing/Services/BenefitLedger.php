<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Models\BenefitPeriod;
use App\Domain\Billing\Models\BenefitProgram;
use App\Domain\Billing\Models\Claim;
use App\Domain\Core\Models\Patient;
use App\Domain\Core\Models\Staff;
use App\Support\Exceptions\DomainRuleException;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use stdClass;

/**
 * What the payer owes, and what has already been claimed against it.
 *
 * Named in CLAUDE.md as the service side of invariant 6: a session is billed at
 * most once. The database backstop is the primary key on claim_sessions.session_id,
 * which stops a duplicate however it arrives; this class refuses it first and says
 * which claim already has it.
 *
 * Nothing here knows a rate or a cap. Both come from effective-dated rows in
 * benefit_programs, because both have already moved -- the PhilHealth case rate
 * went 2,600 -> 4,000 -> 6,350 and the annual allotment 90 -> 156. A number typed
 * into this file would be wrong the next time a circular lands, and every claim
 * built afterwards would be wrong with it.
 *
 * Cross-module access goes through this service, not through Clinical's models:
 * Billing reads treatment_sessions via the query builder and never imports
 * TreatmentSession.
 */
final class BenefitLedger
{
    /**
     * Where a claim may be moved by hand, from each status.
     *
     * approved, partially_paid and paid never appear as destinations: they are
     * what the payer decided, and recordRemittance() sets them from the amounts
     * on the advice. A status picked from a list cannot say how much was paid.
     *
     * Nothing leads back to draft or ready, or to void, once a claim has been
     * submitted. Voiding frees a claim's sessions to be claimed again, and doing
     * that to a claim the payer is holding is how one treatment gets paid twice.
     * A returned claim is refiled as it stands (resubmitted); changing what it
     * covers, like appealing a denial, is not something this system does yet.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        'draft' => ['ready', 'submitted', 'void'],
        'ready' => ['draft', 'submitted', 'void'],
        'submitted' => ['acknowledged', 'in_process', 'returned', 'denied'],
        'acknowledged' => ['in_process', 'returned', 'denied'],
        'in_process' => ['returned', 'denied'],
        'returned' => ['resubmitted'],
        'resubmitted' => ['acknowledged', 'in_process', 'returned', 'denied'],
        'approved' => [],
        'partially_paid' => [],
        'paid' => [],
        'denied' => [],
        'void' => [],
    ];

    /**
     * Where the payer holds the claim, so its remittance advice can be recorded:
     * while it is being processed, and after an approval for the money still to
     * come.
     *
     * @var list<string>
     */
    public const REMITTABLE = ['submitted', 'acknowledged', 'in_process', 'resubmitted', 'approved', 'partially_paid'];

    /** @var list<string> */
    private const DECIDED_BY_REMITTANCE = ['approved', 'partially_paid', 'paid'];

    /**
     * The benefit program in force for a modality on a date.
     *
     * The superseded programs stay in the table so a historical claim re-prices
     * against what was in force when the treatment happened, not what is in force
     * today.
     */
    public function programOn(CarbonInterface $date, string $modality = 'hd'): ?BenefitProgram
    {
        return BenefitProgram::query()
            ->where('modality', $modality)
            ->where('effective_from', '<=', $date->toDateString())
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')->orWhere('effective_to', '>', $date->toDateString());
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * The benefit period covering a date, opened if it does not exist yet.
     *
     * The allotment is copied from the program at the moment the period opens, so
     * a cap change mid-year does not silently rewrite what a patient was already
     * entitled to.
     */
    public function periodFor(Patient $patient, BenefitProgram $program, CarbonInterface $date): BenefitPeriod
    {
        [$start, $end] = $this->periodBounds($program, $date);

        $existing = BenefitPeriod::query()
            ->where('patient_id', $patient->id)
            ->where('program_id', $program->id)
            ->where('period_start', $start)
            ->first();

        if ($existing instanceof BenefitPeriod) {
            return $existing;
        }

        return BenefitPeriod::create([
            'patient_id' => $patient->id,
            'program_id' => $program->id,
            'period_start' => $start,
            'period_end' => $end,
            // Copied from the program now, so a later cap change does not rewrite
            // an entitlement already granted.
            'sessions_allotted' => (int) ($program->sessions_per_period ?? 0),
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * How many sessions this period has left.
     *
     * Read through v_benefit_utilisation so the number a claim is checked against
     * is the same number the report shows.
     */
    public function remaining(BenefitPeriod $period): int
    {
        $row = DB::table('v_benefit_utilisation')->where('benefit_period_id', $period->id)->first();

        return $row === null ? (int) $period->sessions_allotted : (int) $row->sessions_remaining;
    }

    /**
     * Sessions that may still be claimed for a patient, grouped the way a claim
     * has to be built: one benefit program, one benefit period.
     *
     * Locked only. An unsigned record is work nobody has attested to yet, and
     * billing it puts a claim in front of a payer for a treatment the unit has
     * not finished certifying.
     *
     * No lower date bound unless one is asked for. This used to default to the
     * start of the year, so on 1 January every unclaimed December treatment
     * dropped off the billing screen -- revenue nobody notices is missing. A
     * session that cannot be claimed at all (no program in force on its date)
     * is listed with the reason rather than left out, for the same reason.
     *
     * The row id stays here. The client names a session by public_id; the
     * BIGINT never leaves the server.
     *
     * @return array{groups: list<array<string, mixed>>, unclaimable: list<array<string, mixed>>}
     */
    public function claimable(Patient $patient, ?CarbonInterface $from, CarbonInterface $to): array
    {
        $found = DB::table('treatment_sessions as ts')
            ->leftJoin('claim_sessions as cs', 'cs.session_id', '=', 'ts.id')
            ->where('ts.patient_id', $patient->id)
            ->when($from !== null, fn ($query) => $query->where('ts.session_date', '>=', $from?->toDateString()))
            ->where('ts.session_date', '<=', $to->toDateString())
            ->where('ts.status', 'completed')
            ->where('ts.is_billable', 1)
            ->whereNotNull('ts.locked_at')
            ->whereNull('cs.session_id')
            ->orderBy('ts.session_date')
            ->get(['ts.public_id', 'ts.session_date', 'ts.modality']);

        /** @var array<string, array<string, mixed>> $groups */
        $groups = [];
        $unclaimable = [];

        foreach ($found as $row) {
            $date = Carbon::parse((string) $row->session_date);
            $session = [
                'public_id' => (string) $row->public_id,
                'session_date' => $date->toDateString(),
                'modality' => (string) $row->modality,
            ];

            $program = $this->programOn($date, (string) $row->modality);

            if ($program === null) {
                $unclaimable[] = $session + [
                    'reason' => "No benefit program is in force on {$date->toDateString()} for {$row->modality}.",
                ];

                continue;
            }

            try {
                [$start, $end] = $this->periodBounds($program, $date);
            } catch (DomainRuleException $refusal) {
                $unclaimable[] = $session + ['reason' => $refusal->getMessage()];

                continue;
            }

            $key = $program->id.'|'.$start;

            if (! array_key_exists($key, $groups)) {
                $groups[$key] = [
                    'program' => $program->toPayload(),
                    'period_start' => $start,
                    'period_end' => $end,
                    'utilisation' => $this->utilisationOfPeriod($patient, $program, $start),
                    'sessions' => [],
                ];
            }

            $groups[$key]['sessions'][] = $session;
        }

        return ['groups' => array_values($groups), 'unclaimable' => $unclaimable];
    }

    /**
     * Allotted, claimed and remaining for one period -- off the view when the
     * period is open, off the program when it is not yet.
     *
     * Read-only on purpose: listing what could be claimed must not open a
     * benefit period as a side effect. periodFor() opens one when a claim is
     * actually generated, and copies the same sessions_per_period this reads.
     *
     * @return array{sessions_allotted: int, sessions_claimed: int, sessions_remaining: int}
     */
    private function utilisationOfPeriod(Patient $patient, BenefitProgram $program, string $periodStart): array
    {
        $row = DB::table('v_benefit_utilisation as u')
            ->join('benefit_periods as bp', 'bp.id', '=', 'u.benefit_period_id')
            ->where('bp.patient_id', $patient->id)
            ->where('bp.program_id', $program->id)
            ->where('bp.period_start', $periodStart)
            ->first(['u.sessions_allotted', 'u.sessions_claimed', 'u.sessions_remaining']);

        if ($row === null) {
            $allotted = (int) ($program->sessions_per_period ?? 0);

            return ['sessions_allotted' => $allotted, 'sessions_claimed' => 0, 'sessions_remaining' => $allotted];
        }

        return [
            'sessions_allotted' => (int) $row->sessions_allotted,
            'sessions_claimed' => (int) $row->sessions_claimed,
            'sessions_remaining' => (int) $row->sessions_remaining,
        ];
    }

    /**
     * Why this session cannot be claimed, or null if it can.
     *
     * Returns rather than throws so a billing screen can show the whole batch
     * with a reason beside each rejected line, instead of failing on the first.
     */
    public function refusalReason(stdClass $session): ?string
    {
        if ($session->locked_at === null) {
            return "Session {$session->public_id} is not signed. Only a locked record may be claimed.";
        }

        if ((int) $session->is_billable !== 1) {
            return "Session {$session->public_id} is marked not billable.";
        }

        if ($session->status !== 'completed') {
            return "Session {$session->public_id} is {$session->status}; only a completed treatment is claimable.";
        }

        $existing = DB::table('claim_sessions')
            ->join('claims', 'claims.id', '=', 'claim_sessions.claim_id')
            ->where('claim_sessions.session_id', $session->id)
            ->value('claims.claim_no');

        if ($existing !== null) {
            return "Session {$session->public_id} is already on claim {$existing}.";
        }

        // The row exists but the claim carries no number yet.
        if (DB::table('claim_sessions')->where('session_id', $session->id)->exists()) {
            return "Session {$session->public_id} has already been claimed.";
        }

        return null;
    }

    /**
     * Build one claim covering a set of sessions.
     *
     * Everything is checked before anything is written, and the whole thing is one
     * transaction: a claim that covers four of six sessions because the fifth was
     * a duplicate is worse than no claim at all, because someone has to work out
     * which two are missing.
     *
     * Sessions are named by public_id. An earlier version took the BIGINT row
     * ids, which meant the claimable list had to hand them out -- the one thing
     * the repo says never leaves the server.
     *
     * @param  list<string>  $sessionPublicIds
     */
    public function generateClaim(Patient $patient, array $sessionPublicIds, Staff $actor): Claim
    {
        $sessionPublicIds = array_values(array_unique($sessionPublicIds));

        if ($sessionPublicIds === []) {
            throw new DomainRuleException('No sessions were given to claim.');
        }

        return DB::transaction(function () use ($patient, $sessionPublicIds, $actor): Claim {
            $sessions = DB::table('treatment_sessions')
                ->whereIn('public_id', $sessionPublicIds)
                ->lockForUpdate()
                ->orderBy('session_date')
                ->get(['id', 'public_id', 'patient_id', 'session_date', 'status', 'is_billable', 'locked_at', 'modality']);

            if ($sessions->count() !== count($sessionPublicIds)) {
                throw new DomainRuleException('One or more of those sessions does not exist.');
            }

            /** @var list<int> $sessionIds */
            $sessionIds = $sessions->map(fn (stdClass $row): int => (int) $row->id)->values()->all();

            foreach ($sessions as $session) {
                if ((int) $session->patient_id !== (int) $patient->id) {
                    throw new DomainRuleException(
                        "Session {$session->public_id} belongs to a different patient and cannot go on this claim."
                    );
                }

                $reason = $this->refusalReason($session);

                if ($reason !== null) {
                    throw new DomainRuleException($reason);
                }
            }

            $first = $sessions->first();
            $last = $sessions->last();

            // The count check above guarantees both exist; narrowing keeps that
            // guarantee visible rather than implied.
            if (! $first instanceof stdClass || ! $last instanceof stdClass) {
                throw new DomainRuleException('No sessions were given to claim.');
            }

            $serviceFrom = Carbon::parse($first->session_date);
            $serviceTo = Carbon::parse($last->session_date);

            $program = $this->programOn($serviceFrom, (string) $first->modality);

            if ($program === null) {
                throw new DomainRuleException(
                    'No benefit program is effective on '.$serviceFrom->toDateString()
                    .' for modality '.$first->modality.'.'
                );
            }

            $this->assertOneProgramAndPeriod($sessions->all(), $program, $serviceFrom);

            $period = $this->periodFor($patient, $program, $serviceFrom);
            $remaining = $this->remaining($period);

            if ($sessions->count() > $remaining) {
                throw new DomainRuleException(sprintf(
                    'This claim covers %d session(s) but only %d of the %d allotted remain in the period ending %s.',
                    $sessions->count(),
                    $remaining,
                    (int) $period->sessions_allotted,
                    $period->period_end->toDateString(),
                ));
            }

            $caseRate = $program->case_rate;

            $claimId = DB::table('claims')->insertGetId([
                'claim_no' => $this->nextClaimNumber($serviceFrom),
                'patient_id' => $patient->id,
                'payer_id' => $program->payer_id,
                'program_id' => $program->id,
                'benefit_period_id' => $period->id,
                'coverage_id' => $this->coverageId($patient, $program, $serviceFrom),
                'service_from' => $serviceFrom->toDateString(),
                'service_to' => $serviceTo->toDateString(),
                'session_count' => $sessions->count(),
                // Priced from the effective-dated program, never from a constant.
                'amount_claimed' => $program->amountFor($sessions->count()),
                'status' => 'draft',
                'created_at' => Carbon::now(),
                'created_by' => $actor->id,
            ]);

            // "session 47 of 156" -- the sequence the payer's own portal shows.
            $sequence = (int) $period->sessions_allotted - $remaining;

            foreach ($sessions as $session) {
                DB::table('claim_sessions')->insert([
                    'session_id' => $session->id,
                    'claim_id' => $claimId,
                    'benefit_seq_no' => ++$sequence,
                    'amount' => $caseRate,
                ]);
            }

            DB::table('treatment_sessions')
                ->whereIn('id', $sessionIds)
                ->update(['benefit_claim_id' => $claimId]);

            $this->recordStatus($claimId, 'draft', $actor, 'Claim generated.');

            $claim = Claim::query()->find($claimId);

            if (! $claim instanceof Claim) {
                throw new DomainRuleException('Claim vanished immediately after insert.');
            }

            return $claim;
        });
    }

    /**
     * Every session on a claim must fall under the program and the benefit
     * period of the first one.
     *
     * The claim is priced, counted and numbered against a single program and a
     * single period. It used to take both from the first session and apply them
     * to the rest, so a claim running from 29 December to 3 January spent the
     * January treatments from the old year's allotment, numbered them in the
     * old year's sequence, and priced them at whatever rate the old year had.
     * Nothing about that is visible on the claim afterwards. It is refused,
     * naming the session that crosses, so it can be split in two.
     *
     * @param  array<int, stdClass>  $sessions
     */
    private function assertOneProgramAndPeriod(array $sessions, BenefitProgram $program, CarbonInterface $serviceFrom): void
    {
        [$periodStart, $periodEnd] = $this->periodBounds($program, $serviceFrom);

        foreach ($sessions as $session) {
            $date = Carbon::parse((string) $session->session_date);
            $its = $this->programOn($date, (string) $session->modality);

            if ($its === null) {
                throw new DomainRuleException(
                    "Session {$session->public_id} ({$date->toDateString()}) has no benefit program in force "
                    ."for {$session->modality}, so it cannot be claimed."
                );
            }

            if ((int) $its->id !== (int) $program->id) {
                throw new DomainRuleException(
                    "This claim mixes benefit programs: it starts under {$program->code}, but session "
                    ."{$session->public_id} ({$date->toDateString()}) falls under {$its->code}. "
                    .'Claim each program separately -- each has its own rate and allotment.'
                );
            }

            [$start] = $this->periodBounds($its, $date);

            if ($start !== $periodStart) {
                throw new DomainRuleException(
                    "This claim spans two benefit periods: it starts in the period {$periodStart} to {$periodEnd}, "
                    ."but session {$session->public_id} ({$date->toDateString()}) belongs to a later one. "
                    .'Claim each period separately -- the allotment and the session numbering belong to one period.'
                );
            }
        }
    }

    /**
     * Record what the payer actually decided.
     *
     * A claim reaching `paid` used to mean nothing had been checked: the status
     * moved and no money was ever recorded against it. This is the step that
     * makes the difference between "we asked for 6,350" and "they approved 6,350
     * and remitted 6,350", which is the entire point of chasing a payer.
     *
     * The status follows the money rather than being asserted alongside it, so a
     * claim cannot be marked paid while its remittance says otherwise.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordRemittance(Claim $claim, array $attributes, Staff $actor): Claim
    {
        return DB::transaction(function () use ($claim, $attributes, $actor): Claim {
            // Re-read under a lock: two officers keying the same advice must not
            // both pass the checks below from the same starting figures.
            $locked = Claim::query()->whereKey($claim->id)->lockForUpdate()->first();

            if (! $locked instanceof Claim) {
                throw new DomainRuleException('Claim disappeared while its remittance was being recorded.');
            }

            return $this->applyRemittance($locked, $attributes, $actor);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function applyRemittance(Claim $claim, array $attributes, Staff $actor): Claim
    {
        $this->assertAcceptsRemittance($claim);

        $claimed = $this->decimal($claim->amount_claimed ?? '0');
        // Normalised to two places, so the history reads "12700.00" whatever
        // was typed -- the same figure the DECIMAL(12,2) column stores.
        $approved = bcadd($this->decimal($attributes['amount_approved'] ?? '0'), '0', 2);
        $paid = bcadd($this->decimal($attributes['amount_paid'] ?? '0'), '0', 2);

        // A follow-up advice restates the totals, so what was already received
        // can only stay or grow. Keying this payment alone -- 3,000 when 3,350
        // had already come in -- would otherwise overwrite the record of money
        // the unit holds. A payer clawing money back is not modelled at all.
        if ($claim->amount_paid !== null && bccomp($paid, $this->decimal($claim->amount_paid), 2) < 0) {
            throw new DomainRuleException(sprintf(
                'Claim %s already has %s received against it, and paid to date cannot go down. Enter the total '
                .'received on this claim so far, including this payment. Money taken back by the payer is not '
                .'something this system records yet.',
                $claim->claim_no,
                $claim->amount_paid,
            ));
        }

        if (bccomp($approved, $claimed, 2) > 0) {
            throw new DomainRuleException(sprintf(
                'The payer cannot approve %s against a claim for %s. Check the remittance advice.',
                $approved,
                $claimed,
            ));
        }

        if (bccomp($paid, $approved, 2) > 0) {
            throw new DomainRuleException(sprintf(
                'Remitted %s against an approved %s. A payment above the approval needs a corrected advice.',
                $paid,
                $approved,
            ));
        }

        $denied = bccomp($approved, '0', 2) === 0;

        if ($denied && ($attributes['denial_code'] ?? null) === null) {
            throw new DomainRuleException('A claim approved at zero is a denial, and a denial needs a code to resubmit against.');
        }

        // The status is derived, not supplied. A claim marked paid whose
        // remittance says otherwise is exactly the disagreement to prevent.
        $status = match (true) {
            $denied => 'denied',
            bccomp($paid, $approved, 2) === 0 => 'paid',
            bccomp($paid, '0', 2) > 0 => 'partially_paid',
            default => 'approved',
        };

        DB::table('claims')->where('id', $claim->id)->update(array_filter([
            'amount_approved' => $approved,
            'amount_paid' => $paid,
            'status' => $status,
            'denial_code' => $attributes['denial_code'] ?? null,
            'denial_reason' => $attributes['denial_reason'] ?? null,
            'external_ref' => $attributes['external_ref'] ?? null,
            'paid_at' => $status === 'paid' ? Carbon::now() : null,
        ], fn (mixed $value): bool => $value !== null));

        $this->recordStatus(
            (int) $claim->id,
            $status,
            $actor,
            sprintf('Remittance: approved %s, paid %s of %s claimed.', $approved, $paid, $claim->amount_claimed),
        );

        $fresh = Claim::query()->find($claim->id);

        if (! $fresh instanceof Claim) {
            throw new DomainRuleException('Claim disappeared while its remittance was being recorded.');
        }

        return $fresh;
    }

    /**
     * A remittance is the payer's answer to a claim it holds.
     *
     * With no check here, an advice keyed against a draft marked a claim paid
     * that had never been sent, and one keyed against a void claim brought it
     * back to life as paid -- with the money counted in what the unit is owed.
     */
    private function assertAcceptsRemittance(Claim $claim): void
    {
        if (in_array($claim->status, self::REMITTABLE, true)) {
            return;
        }

        throw new DomainRuleException(match ($claim->status) {
            'draft', 'ready' => "Claim {$claim->claim_no} has not been submitted. A remittance is the payer's answer "
                .'to a claim it has received -- record the submission first.',
            'returned' => "Claim {$claim->claim_no} was returned by the payer. Resubmit it first; a remittance answers "
                .'a claim the payer is processing.',
            'paid' => "Claim {$claim->claim_no} is already paid in full.",
            'denied' => "Claim {$claim->claim_no} was denied. Reversing a denial is an appeal, which this system does "
                .'not record yet.',
            'void' => "Claim {$claim->claim_no} is void and takes no remittance.",
            default => "Claim {$claim->claim_no} is {$claim->status} and cannot take a remittance.",
        });
    }

    /**
     * The claims list: newest service first, optionally one status, optionally
     * a claim number, MRN or name.
     *
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function search(?string $status, ?string $term, int $perPage): LengthAwarePaginator
    {
        return DB::table('claims as c')
            ->join('patients as p', 'p.id', '=', 'c.patient_id')
            ->leftJoin('benefit_programs as bp', 'bp.id', '=', 'c.program_id')
            ->when($status !== null, fn ($query) => $query->where('c.status', $status))
            ->when($term !== null, function ($query) use ($term): void {
                $like = '%'.addcslashes((string) $term, '%_\\').'%';

                $query->where(function ($inner) use ($like): void {
                    $inner->where('c.claim_no', 'like', $like)
                        ->orWhere('p.mrn', 'like', $like)
                        ->orWhere('p.full_name', 'like', $like);
                });
            })
            ->orderByDesc('c.service_from')
            ->orderByDesc('c.id')
            ->paginate(max(1, min($perPage, 100)), [
                'c.claim_no', 'c.status', 'c.service_from', 'c.service_to',
                'c.session_count', 'c.amount_claimed', 'c.amount_approved', 'c.amount_paid',
                'p.public_id as patient_public_id', 'p.mrn', 'p.full_name', 'bp.code as program_code',
            ]);
    }

    /**
     * Everything the claim screen shows, including where the claim may go next.
     *
     * @return array<string, mixed>
     */
    public function detail(Claim $claim): array
    {
        $patient = DB::table('patients')->where('id', $claim->patient_id)->first(['public_id', 'mrn', 'full_name']);
        $program = $claim->program_id === null ? null : BenefitProgram::query()->find($claim->program_id);

        $utilisation = $claim->benefit_period_id === null ? null : DB::table('v_benefit_utilisation')
            ->where('benefit_period_id', $claim->benefit_period_id)
            ->first(['period_start', 'period_end', 'sessions_allotted', 'sessions_claimed', 'sessions_remaining']);

        return [
            'claim_no' => $claim->claim_no,
            'status' => $claim->status,
            'patient' => $patient === null ? null : [
                'public_id' => $patient->public_id,
                'mrn' => $patient->mrn,
                'full_name' => $patient->full_name,
            ],
            'program' => $program?->toPayload(),
            'utilisation' => $utilisation === null ? null : [
                'period_start' => (string) $utilisation->period_start,
                'period_end' => (string) $utilisation->period_end,
                'sessions_allotted' => (int) $utilisation->sessions_allotted,
                'sessions_claimed' => (int) $utilisation->sessions_claimed,
                'sessions_remaining' => (int) $utilisation->sessions_remaining,
            ],
            'service_from' => $claim->service_from->toDateString(),
            'service_to' => $claim->service_to->toDateString(),
            'session_count' => (int) $claim->session_count,
            'amount_claimed' => $claim->amount_claimed,
            'amount_approved' => $claim->amount_approved,
            'amount_paid' => $claim->amount_paid,
            // Owed against what was approved, and null until something was: before
            // an approval nothing is owed, only asked for. Computed here in bcmath
            // so no client subtracts money in floating point.
            'amount_outstanding' => $claim->amount_approved === null
                ? null
                : bcsub($this->decimal($claim->amount_approved), $this->decimal($claim->amount_paid ?? '0'), 2),
            'denial_code' => $claim->denial_code,
            'denial_reason' => $claim->denial_reason,
            'external_ref' => $claim->external_ref,
            'submitted_at' => $claim->submitted_at?->toIso8601String(),
            'acknowledged_at' => $claim->acknowledged_at?->toIso8601String(),
            'paid_at' => $claim->paid_at?->toIso8601String(),
            'next_statuses' => $this->nextStatuses($claim),
            'accepts_remittance' => $this->acceptsRemittance($claim),
            'sessions' => DB::table('claim_sessions as cs')
                ->join('treatment_sessions as ts', 'ts.id', '=', 'cs.session_id')
                ->where('cs.claim_id', $claim->id)
                ->orderBy('cs.benefit_seq_no')
                ->get(['ts.public_id', 'ts.session_date', 'ts.modality', 'cs.benefit_seq_no', 'cs.amount'])
                ->map(fn (stdClass $row): array => [
                    'public_id' => $row->public_id,
                    'session_date' => (string) $row->session_date,
                    'modality' => $row->modality,
                    'benefit_seq_no' => $row->benefit_seq_no === null ? null : (int) $row->benefit_seq_no,
                    'amount' => $row->amount,
                ])
                ->all(),
            'history' => DB::table('claim_status_histories as h')
                ->leftJoin('staff as s', 's.id', '=', 'h.changed_by')
                ->where('h.claim_id', $claim->id)
                ->orderBy('h.changed_at')
                ->orderBy('h.id')
                ->get(['h.status', 'h.changed_at', 'h.remarks', 's.full_name as changed_by'])
                ->map(fn (stdClass $row): array => [
                    'status' => $row->status,
                    // A raw row's DATETIME has no zone on it; stored UTC, so it
                    // leaves as UTC (CLAUDE.md, MySQL rule 11).
                    'changed_at' => Carbon::parse((string) $row->changed_at, 'UTC')->toIso8601String(),
                    'remarks' => $row->remarks,
                    'changed_by' => $row->changed_by,
                ])
                ->all(),
        ];
    }

    /**
     * The shortfall between what was claimed and what was remitted.
     *
     * What a billing officer chases. Reads the claims table directly rather than
     * recomputing per claim, because "how much are we owed" is one question.
     *
     * Only claims that have gone to a payer. A claim marked ready has not been
     * asked for yet, so counting it put money in "claimed" that no payer had
     * seen.
     *
     * @return array{claimed: string, approved: string, paid: string, outstanding: string}
     */
    public function outstanding(?string $from = null, ?string $to = null): array
    {
        $row = DB::table('claims')
            ->whereNotIn('status', ['void', 'draft', 'ready'])
            ->when($from !== null, fn ($query) => $query->where('service_from', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('service_to', '<=', $to))
            ->selectRaw('COALESCE(SUM(amount_claimed),0) AS claimed')
            ->selectRaw('COALESCE(SUM(amount_approved),0) AS approved')
            ->selectRaw('COALESCE(SUM(amount_paid),0) AS paid')
            ->first();

        $claimed = $this->decimal($row->claimed ?? '0');
        $approved = $this->decimal($row->approved ?? '0');
        $paid = $this->decimal($row->paid ?? '0');

        return [
            'claimed' => $claimed,
            'approved' => $approved,
            'paid' => $paid,
            // Against what was approved, not what was asked for: the gap between
            // claimed and approved is a denial to appeal, not a debt to chase.
            'outstanding' => bcsub($approved, $paid, 2),
        ];
    }

    /**
     * @return numeric-string
     */
    private function decimal(mixed $value): string
    {
        $string = (string) $value;

        if (! is_numeric($string)) {
            throw new DomainRuleException("Expected a decimal amount but got: {$string}");
        }

        return $string;
    }

    /**
     * Move a claim through its lifecycle by hand, keeping the history.
     *
     * Only along TRANSITIONS. This used to accept any status from any status,
     * so a claim could be marked `paid` with no remittance and no money against
     * it (the disagreement recordRemittance() exists to prevent), a paid claim
     * could be put back to draft, and a void one reopened.
     */
    public function transition(Claim $claim, string $status, Staff $actor, ?string $remarks = null): Claim
    {
        return DB::transaction(function () use ($claim, $status, $actor, $remarks): Claim {
            // Re-read under a lock so two officers moving the same claim cannot
            // both succeed from the same starting status.
            $current = (string) DB::table('claims')->where('id', $claim->id)->lockForUpdate()->value('status');

            $this->assertTransition((string) $claim->claim_no, $current, $status);

            $update = ['status' => $status];

            if ($status === 'submitted') {
                $update['submitted_at'] = Carbon::now();
            }

            if ($status === 'acknowledged') {
                $update['acknowledged_at'] = Carbon::now();
            }

            if ($status === 'void') {
                $remarks = $this->releaseSessions($claim, $actor, (string) $remarks);
            }

            DB::table('claims')->where('id', $claim->id)->update($update);

            $this->recordStatus((int) $claim->id, $status, $actor, $remarks);

            $fresh = Claim::query()->find($claim->id);

            if (! $fresh instanceof Claim) {
                throw new DomainRuleException('Claim disappeared while being updated.');
            }

            return $fresh;
        });
    }

    /**
     * The statuses a claim may be moved to by hand from where it is now -- sent
     * to the screen so it asks the rule instead of keeping its own copy.
     *
     * @return list<string>
     */
    public function nextStatuses(Claim $claim): array
    {
        return self::TRANSITIONS[$claim->status] ?? [];
    }

    public function acceptsRemittance(Claim $claim): bool
    {
        return in_array($claim->status, self::REMITTABLE, true);
    }

    private function assertTransition(string $claimNo, string $from, string $to): void
    {
        if (in_array($to, self::DECIDED_BY_REMITTANCE, true)) {
            throw new DomainRuleException(
                "A claim becomes {$to} when the payer's remittance is recorded -- the amounts on the advice decide "
                .'it, not a status picked by hand. Record the remittance instead.'
            );
        }

        if (in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            return;
        }

        $sent = ['submitted', 'acknowledged', 'in_process', 'returned', 'resubmitted'];

        throw new DomainRuleException(match (true) {
            in_array($from, $sent, true) && in_array($to, ['draft', 'ready', 'void'], true) => "Claim {$claimNo} has "
                .'gone to the payer, so it cannot go back to draft or ready, or be voided. The payer already has it, '
                .'and freeing its sessions for a new claim would bill them twice.',
            $from === 'void' => "Claim {$claimNo} is void. A void claim is never reopened; generate a new one.",
            $from === 'paid' => "Claim {$claimNo} is paid. Nothing moves it from here.",
            $from === 'denied' => "Claim {$claimNo} was denied. An appeal is not something this system records yet; "
                .'the denial code is kept for the follow-up.',
            in_array($from, ['approved', 'partially_paid'], true) => "Claim {$claimNo} has been decided by the payer. "
                .'Only a further remittance advice moves it now.',
            default => "Claim {$claimNo} is {$from} and cannot move to {$to}. From {$from} it can go to: "
                .implode(', ', self::TRANSITIONS[$from] ?? []).'.',
        });
    }

    /**
     * Free a voided claim's sessions so they can be claimed again.
     *
     * Only reachable from draft or ready -- a claim the payer never saw. The
     * primary key on claim_sessions.session_id is invariant 6's backstop, so a
     * session cannot sit on a void claim and a live one at once. Leaving the rows
     * in place stranded those treatments: v_benefit_utilisation already stops
     * counting a void claim against the allotment, but nothing could ever claim
     * the sessions again, and the only way out was a hand-written DELETE.
     *
     * Every row is written to audit_logs before it goes, so what the void claim
     * covered stays answerable.
     *
     * @return string the history remark, carrying the reason and what was freed
     */
    private function releaseSessions(Claim $claim, Staff $actor, string $reason): string
    {
        $rows = DB::table('claim_sessions as cs')
            ->join('treatment_sessions as ts', 'ts.id', '=', 'cs.session_id')
            ->where('cs.claim_id', $claim->id)
            ->orderBy('cs.benefit_seq_no')
            ->get(['cs.session_id', 'cs.benefit_seq_no', 'cs.amount', 'ts.public_id', 'ts.session_date']);

        foreach ($rows as $row) {
            DB::table('audit_logs')->insert([
                'occurred_at' => Carbon::now(),
                'actor_id' => $actor->id,
                'actor_name' => $actor->full_name,
                'action' => 'DELETE',
                // No model behind claim_sessions; the table: prefix is the same
                // convention SettingsService uses for its rows.
                'auditable_type' => 'table:claim_sessions',
                'auditable_id' => $row->session_id,
                'before_data' => json_encode([
                    'claim_no' => $claim->claim_no,
                    'session_public_id' => $row->public_id,
                    'session_date' => $row->session_date,
                    'benefit_seq_no' => $row->benefit_seq_no,
                    'amount' => $row->amount,
                ], JSON_THROW_ON_ERROR),
                'ip_address' => Request::ip(),
                'user_agent' => substr((string) Request::userAgent(), 0, 255),
                'reason' => mb_substr('Claim voided: '.$reason, 0, 255),
            ]);
        }

        DB::table('treatment_sessions')
            ->where('benefit_claim_id', $claim->id)
            ->update(['benefit_claim_id' => null]);

        DB::table('claim_sessions')->where('claim_id', $claim->id)->delete();

        $suffix = sprintf(' (%d session(s) released to be claimed again)', $rows->count());

        return mb_substr($reason, 0, 255 - mb_strlen($suffix)).$suffix;
    }

    private function recordStatus(int $claimId, string $status, Staff $actor, ?string $remarks): void
    {
        DB::table('claim_status_histories')->insert([
            'claim_id' => $claimId,
            'status' => $status,
            'changed_at' => Carbon::now(),
            'changed_by' => $actor->id,
            'remarks' => $remarks,
        ]);
    }

    /**
     * Start and end of the benefit period containing a date.
     *
     * @return array{0: string, 1: string}
     */
    private function periodBounds(BenefitProgram $program, CarbonInterface $date): array
    {
        $at = Carbon::parse($date->toDateString());

        return match ($program->period_kind) {
            'calendar_year' => [$at->copy()->startOfYear()->toDateString(), $at->copy()->endOfYear()->toDateString()],
            'month' => [$at->copy()->startOfMonth()->toDateString(), $at->copy()->endOfMonth()->toDateString()],
            // A rolling year is anchored to the patient's first claim and lifetime
            // has no bounds at all. Both need a rule this codebase has not been
            // given, and guessing one would silently mis-state an entitlement.
            default => throw new DomainRuleException(
                "Benefit period kind '{$program->period_kind}' is not implemented; it needs a defined anchor date."
            ),
        };
    }

    private function coverageId(Patient $patient, BenefitProgram $program, CarbonInterface $date): ?int
    {
        $id = DB::table('patient_coverages')
            ->where('patient_id', $patient->id)
            ->where('payer_id', $program->payer_id)
            ->where('effective_from', '<=', $date->toDateString())
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $date->toDateString());
            })
            ->orderBy('priority')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /** Sequential per month, which is how the payer's portal expects them grouped. */
    private function nextClaimNumber(CarbonInterface $serviceFrom): string
    {
        $prefix = 'CLM-'.$serviceFrom->format('Ym').'-';

        $last = DB::table('claims')
            ->where('claim_no', 'like', $prefix.'%')
            ->orderByDesc('claim_no')
            ->value('claim_no');

        $next = $last === null ? 1 : ((int) substr((string) $last, -4)) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
