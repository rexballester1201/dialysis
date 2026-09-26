<?php

declare(strict_types=1);

namespace App\Domain\Core\Services;

use App\Domain\Core\Models\Staff;
use App\Support\Exceptions\DomainRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * Unit configuration.
 *
 * Everything here changes how the rest of the system behaves, and three of them
 * change what the system will let a nurse do to a patient. None of these tables
 * has an Eloquent model, so the Auditable trait cannot see them -- every write
 * in this class records its own audit entry instead. A settings change that
 * loosens a safety rule and leaves no trace of who loosened it is worse than
 * one that never happened.
 */
final class SettingsService
{
    /**
     * Everything the settings screen needs, in one read.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return [
            'facility' => $this->facility(),
            'stations' => $this->stations(),
            'benefit_programs' => $this->benefitPrograms(),
            'high_alert' => $this->medications(),
            'staff' => $this->staff(),
            'roles' => DB::table('roles')->orderBy('code')->get(['code', 'name', 'description']),
        ];
    }

    /** @return array<string, mixed>|null */
    public function facility(): ?array
    {
        $row = DB::table('facilities')->orderBy('id')->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * Chairs, with the cohorts each is dedicated to.
     *
     * An empty `cohorts` array is not "no cohorts allowed" -- it is
     * *unrestricted*, and CohortGuard::stationAccepts() treats it that way. The
     * screen has to say so, because the intuitive reading is the opposite one
     * and acting on it would seat an HBV patient in a clean chair.
     *
     * @return array<int, array<string, mixed>>
     */
    public function stations(): array
    {
        $cohorts = DB::table('station_cohorts')->get()->groupBy('station_id');

        return DB::table('stations')
            ->orderBy('code')
            ->get(['id', 'code', 'kind', 'room', 'is_active'])
            ->map(function (object $station) use ($cohorts): array {
                return [
                    'id' => (int) $station->id,
                    'code' => $station->code,
                    'kind' => $station->kind,
                    'room' => $station->room,
                    'is_active' => (bool) $station->is_active,
                    'cohorts' => $cohorts->get($station->id, collect())->pluck('cohort')->values()->all(),
                ];
            })
            ->all();
    }

    /**
     * Effective-dated, newest first. Superseded rows are kept, never edited.
     *
     * @return array<int, array<string, mixed>>
     */
    public function benefitPrograms(): array
    {
        return DB::table('benefit_programs as bp')
            ->leftJoin('payers as p', 'p.id', '=', 'bp.payer_id')
            ->orderByDesc('bp.effective_from')
            ->get([
                'bp.id', 'bp.code', 'bp.name', 'bp.modality', 'bp.case_rate',
                'bp.sessions_per_period', 'bp.period_kind', 'bp.no_balance_billing',
                'bp.effective_from', 'bp.effective_to', 'bp.circular_ref', 'bp.currency',
                'p.name as payer_name',
            ])
            ->map(fn (object $row): array => (array) $row)
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function medications(): array
    {
        return DB::table('medication_refs')
            ->orderByDesc('is_high_alert')
            ->orderBy('generic_name')
            ->get(['id', 'generic_name', 'brand_name', 'strength', 'unit', 'is_high_alert'])
            // The override goes on the LEFT: `+` keeps the left-hand value on a
            // key collision, so `(array) $row + [...]` would silently discard
            // the cast and ship MySQL's 0/1 to a client expecting a boolean.
            ->map(fn (object $row): array => ['is_high_alert' => (bool) $row->is_high_alert] + (array) $row)
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function staff(): array
    {
        $roles = DB::table('role_staff')->get()->groupBy('staff_id');

        return DB::table('staff')
            ->whereNull('deleted_at')
            ->orderBy('full_name')
            ->get(['id', 'public_id', 'full_name', 'employee_no', 'email', 'is_active'])
            ->map(fn (object $s): array => [
                'public_id' => $s->public_id,
                'full_name' => $s->full_name,
                'employee_no' => $s->employee_no,
                'email' => $s->email,
                'is_active' => (bool) $s->is_active,
                'roles' => $roles->get($s->id, collect())->pluck('role_code')->values()->all(),
            ])
            ->values()
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /*  Writes */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function updateFacility(array $attributes, Staff $actor): array
    {
        $before = $this->facility();

        if ($before === null) {
            throw new DomainRuleException('No facility row exists to update.');
        }

        $id = (int) $before['id'];

        DB::table('facilities')->where('id', $id)->update($attributes + ['updated_at' => Carbon::now()]);

        $after = $this->facility() ?? $before;
        $this->record($actor, 'UPDATE', 'facilities', $id, $before, $after);

        return $after;
    }

    /**
     * Set which cohorts a chair may hold.
     *
     * Passing an empty list makes the chair unrestricted, which is a widening of
     * infection-control segregation and is recorded as such. The service does
     * not refuse it -- a unit that genuinely has one general-purpose chair needs
     * to be able to say so -- but it never happens silently.
     *
     * @param  list<string>  $cohorts
     * @return array<int, array<string, mixed>>
     */
    public function setStationCohorts(int $stationId, array $cohorts, Staff $actor): array
    {
        $station = DB::table('stations')->where('id', $stationId)->first();

        if ($station === null) {
            throw new DomainRuleException('No such station.');
        }

        $valid = ['clean', 'hbv', 'hcv'];

        foreach ($cohorts as $cohort) {
            if (! in_array($cohort, $valid, true)) {
                throw new DomainRuleException("'{$cohort}' is not an infection-control cohort.");
            }
        }

        $before = DB::table('station_cohorts')->where('station_id', $stationId)->pluck('cohort')->all();

        DB::transaction(function () use ($stationId, $cohorts): void {
            DB::table('station_cohorts')->where('station_id', $stationId)->delete();

            foreach (array_unique($cohorts) as $cohort) {
                DB::table('station_cohorts')->insert(['station_id' => $stationId, 'cohort' => $cohort]);
            }
        });

        $this->record(
            $actor,
            'UPDATE',
            'station_cohorts',
            $stationId,
            ['station' => $station->code, 'cohorts' => $before],
            ['station' => $station->code, 'cohorts' => array_values(array_unique($cohorts))],
        );

        return $this->stations();
    }

    /**
     * Add a benefit programme.
     *
     * Deliberately add-only. A rate is never edited in place, because a claim
     * generated last year has to re-price against the rate that was in force on
     * its service date -- the PhilHealth case rate has moved twice and the
     * session allotment once. Superseding a programme closes the previous one
     * on the new start date -- effective_to is exclusive -- and leaves it on file.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<int, array<string, mixed>>
     */
    public function addBenefitProgram(array $attributes, Staff $actor): array
    {
        $from = Carbon::parse($attributes['effective_from']);

        // `code` is globally unique, so each version of a programme carries its
        // own -- PH_HD_156_2023 superseded by PH_HD_CURRENT, not two rows
        // sharing a code. Caught here so the answer is a sentence rather than a
        // duplicate-key 500.
        if (DB::table('benefit_programs')->where('code', $attributes['code'])->exists()) {
            throw new DomainRuleException(sprintf(
                'The code %s is already used by another programme. Each version needs its own, e.g. %s_%s.',
                $attributes['code'],
                $attributes['code'],
                $from->year,
            ));
        }

        // BenefitLedger::programFor() resolves on modality and date alone, so
        // that -- not the code, and not the payer -- is what "the one in force"
        // means.
        $current = DB::table('benefit_programs')
            ->where('modality', $attributes['modality'])
            ->whereNull('effective_to')
            ->orderByDesc('effective_from')
            ->first();

        if ($current !== null && Carbon::parse($current->effective_from)->gte($from)) {
            throw new DomainRuleException(sprintf(
                'The %s programme in force already starts on %s. A new one must start after it.',
                $attributes['modality'],
                Carbon::parse($current->effective_from)->toDateString(),
            ));
        }

        DB::transaction(function () use ($attributes, $from, $current, $actor): void {
            if ($current !== null) {
                // effective_to is EXCLUSIVE -- programFor() matches on
                // `effective_to > date`. Closing on the new row's start date is
                // therefore exact and gapless; closing a day earlier would leave
                // one day with no programme in force, and a claim generated on
                // that day would price against nothing.
                $closesOn = $from->toDateString();

                DB::table('benefit_programs')->where('id', $current->id)->update(['effective_to' => $closesOn]);

                $this->record($actor, 'UPDATE', 'benefit_programs', (int) $current->id,
                    ['code' => $current->code, 'effective_to' => null],
                    ['code' => $current->code, 'effective_to' => $closesOn]);
            }

            $id = DB::table('benefit_programs')->insertGetId($attributes);

            $this->record($actor, 'INSERT', 'benefit_programs', (int) $id, null, $attributes);
        });

        return $this->benefitPrograms();
    }

    /**
     * Flag or unflag a high-alert medication.
     *
     * Unflagging removes the witness requirement for that drug -- invariant 7
     * reads this column, and the medication_administrations_bi trigger reads it
     * too. It is the difference between a dose needing an independent second
     * check and not.
     *
     * @return array<int, array<string, mixed>>
     */
    public function setHighAlert(int $medicationId, bool $highAlert, Staff $actor): array
    {
        $before = DB::table('medication_refs')->where('id', $medicationId)->first();

        if ($before === null) {
            throw new DomainRuleException('No such medication.');
        }

        DB::table('medication_refs')->where('id', $medicationId)->update(['is_high_alert' => $highAlert]);

        $this->record(
            $actor,
            'UPDATE',
            'medication_refs',
            $medicationId,
            ['generic_name' => $before->generic_name, 'is_high_alert' => (bool) $before->is_high_alert],
            ['generic_name' => $before->generic_name, 'is_high_alert' => $highAlert],
        );

        return $this->medications();
    }

    /**
     * Grant or revoke a role.
     *
     * @param  list<string>  $roleCodes
     * @return array<int, array<string, mixed>>
     */
    public function setStaffRoles(string $staffPublicId, array $roleCodes, Staff $actor): array
    {
        $target = DB::table('staff')->where('public_id', $staffPublicId)->whereNull('deleted_at')->first();

        if ($target === null) {
            throw new DomainRuleException('No such staff member.');
        }

        $known = DB::table('roles')->pluck('code')->all();

        foreach ($roleCodes as $code) {
            if (! in_array($code, $known, true)) {
                throw new DomainRuleException("'{$code}' is not a role in this system.");
            }
        }

        $before = DB::table('role_staff')->where('staff_id', $target->id)->pluck('role_code')->all();

        // Removing your own admin role locks you out of this screen, and with no
        // other administrator there is no way back in without database access.
        if ($target->id === $actor->id && in_array('admin', $before, true) && ! in_array('admin', $roleCodes, true)) {
            $others = DB::table('role_staff')
                ->where('role_code', 'admin')
                ->where('staff_id', '!=', $actor->id)
                ->count();

            if ($others === 0) {
                throw new DomainRuleException(
                    'You are the only administrator. Give someone else the admin role before removing your own.'
                );
            }
        }

        DB::transaction(function () use ($target, $roleCodes): void {
            DB::table('role_staff')->where('staff_id', $target->id)->delete();

            foreach (array_unique($roleCodes) as $code) {
                DB::table('role_staff')->insert([
                    'staff_id' => $target->id,
                    'role_code' => $code,
                    'granted_at' => Carbon::now(),
                ]);
            }
        });

        $this->record(
            $actor,
            'UPDATE',
            'role_staff',
            (int) $target->id,
            ['staff' => $target->full_name, 'roles' => $before],
            ['staff' => $target->full_name, 'roles' => array_values(array_unique($roleCodes))],
        );

        return $this->staff();
    }

    /**
     * Write the audit entry these tables cannot write for themselves.
     *
     * `auditable_type` carries the table name rather than a class name, because
     * there is no model behind it. That is a deliberate difference from the
     * Auditable trait's rows and is what tells the two apart when reading the
     * log later.
     */
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function record(
        Staff $actor,
        /** INSERT|UPDATE|DELETE -- audit_logs.action is CHECK-constrained to SQL verbs. */
        string $action,
        string $table,
        int $id,
        ?array $before,
        ?array $after,
    ): void {
        $changed = $before === null || $after === null
            ? []
            : array_keys(array_diff_assoc(
                array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, $after),
                array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, $before),
            ));

        DB::table('audit_logs')->insert([
            'occurred_at' => Carbon::now(),
            'actor_id' => $actor->id,
            'actor_name' => $actor->full_name,
            'action' => $action,
            'auditable_type' => "table:{$table}",
            'auditable_id' => $id,
            'before_data' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_data' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'changed_cols' => $changed === [] ? null : json_encode($changed, JSON_THROW_ON_ERROR),
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 255),
            'reason' => Request::input('_audit_reason'),
        ]);
    }
}
