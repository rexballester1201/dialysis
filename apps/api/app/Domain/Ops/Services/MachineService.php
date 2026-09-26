<?php

declare(strict_types=1);

namespace App\Domain\Ops\Services;

use App\Domain\Core\Models\Staff;
use App\Domain\Core\Services\FacilityCalendar;
use App\Support\Exceptions\DomainRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The machine register, its disinfection record, and its maintenance history.
 *
 * Two rules are enforced when a machine is put on a patient:
 *
 *   status            a machine under repair, quarantined or retired is not
 *                     available, whatever the board says. This one blocks.
 *   dedicated_cohort  invariant 1 covers machines as well as chairs, and it is
 *                     detective by design -- so a mismatch is reported loudly at
 *                     the moment of assignment rather than refused. See
 *                     v_cohort_violation and dialysis:check-controls.
 */
final class MachineService
{
    public function __construct(private readonly FacilityCalendar $calendar) {}

    /** Statuses in which a machine may be put on a patient. */
    private const AVAILABLE_STATUSES = ['in_service', 'standby'];

    /**
     * Why this machine cannot be used at all, or null if it can.
     *
     * Only hard unavailability. A cohort mismatch is not returned here because
     * it does not block -- it is surfaced separately.
     */
    public function unavailableReason(stdClass $machine): ?string
    {
        if (! in_array($machine->status, self::AVAILABLE_STATUSES, true)) {
            return "Machine {$machine->asset_tag} is {$machine->status} and cannot be used.";
        }

        return null;
    }

    /**
     * @throws DomainRuleException
     */
    public function assertUsable(int $machineId): stdClass
    {
        $machine = $this->find($machineId);

        $reason = $this->unavailableReason($machine);

        if ($reason !== null) {
            throw new DomainRuleException($reason);
        }

        return $machine;
    }

    /**
     * When this machine was last disinfected, and what has run on it since.
     *
     * Deliberately reports a fact rather than a verdict. How often a machine
     * must be disinfected, and by which method, is unit policy -- most units
     * run a cycle between every patient, but the interval belongs in an SOP,
     * not hardcoded here. What the data can say without inventing anything is
     * "this machine has treated N patients since its last recorded cycle", and
     * in a unit that disinfects between patients any N above zero is actionable.
     *
     * @return array{last_disinfected_at: string|null, method: string|null, sessions_since: int, never_disinfected: bool}
     */
    public function disinfectionState(int $machineId): array
    {
        $last = DB::table('machine_disinfection_logs')
            ->where('machine_id', $machineId)
            ->orderByDesc('performed_at')
            ->first(['performed_at', 'method']);

        $lastAt = $last === null ? null : (string) $last->performed_at;

        $since = DB::table('treatment_sessions')
            ->where('machine_id', $machineId)
            ->whereNotNull('started_at')
            ->when($lastAt !== null, fn ($query) => $query->where('started_at', '>', $lastAt))
            ->count();

        return [
            'last_disinfected_at' => $lastAt === null ? null : Carbon::parse($lastAt)->toIso8601String(),
            'method' => $last?->method,
            'sessions_since' => $since,
            'never_disinfected' => $last === null,
        ];
    }

    /**
     * The infection-control cohort of the last patient this machine treated,
     * or null if it has been disinfected since -- or never used.
     *
     * This is a categorical rule rather than an interval one, which is why it
     * can be enforced without a local SOP: a machine that has just run an
     * HBsAg-reactive patient carries that patient's blood path until a cycle is
     * recorded. How *often* a machine must otherwise be cleaned is unit policy;
     * that it must be cleaned between cohorts is not.
     */
    public function cohortCarriedOver(int $machineId): ?string
    {
        $lastClean = DB::table('machine_disinfection_logs')
            ->where('machine_id', $machineId)
            ->max('performed_at');

        $lastPatientCohort = DB::table('treatment_sessions as ts')
            ->join('v_patient_cohort as c', 'c.patient_id', '=', 'ts.patient_id')
            ->where('ts.machine_id', $machineId)
            ->whereNotNull('ts.started_at')
            ->when($lastClean !== null, fn ($query) => $query->where('ts.started_at', '>', $lastClean))
            ->orderByDesc('ts.started_at')
            ->value('c.cohort');

        return $lastPatientCohort === null ? null : (string) $lastPatientCohort;
    }

    /**
     * Infection-control notes about putting this machine on a patient now.
     *
     * @return list<string>
     */
    public function hygieneWarnings(int $machineId): array
    {
        $machine = $this->find($machineId);
        $state = $this->disinfectionState($machineId);
        $warnings = [];

        if ($state['never_disinfected'] && $state['sessions_since'] > 0) {
            $warnings[] = sprintf(
                'Machine %s has no disinfection cycle on record and has run %d treatment(s).',
                $machine->asset_tag,
                $state['sessions_since'],
            );
        } elseif ($state['sessions_since'] > 0) {
            $warnings[] = sprintf(
                'Machine %s has run %d treatment(s) since it was last disinfected (%s).',
                $machine->asset_tag,
                $state['sessions_since'],
                (string) $state['last_disinfected_at'],
            );
        }

        return $warnings;
    }

    /**
     * Record a disinfection cycle.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordDisinfection(int $machineId, array $attributes, Staff $actor): stdClass
    {
        $machine = $this->find($machineId);

        DB::table('machine_disinfection_logs')->insert([
            'machine_id' => $machine->id,
            'performed_at' => $attributes['performed_at'] ?? Carbon::now(),
            'method' => $attributes['method'],
            'agent' => $attributes['agent'] ?? null,
            'duration_min' => $attributes['duration_min'] ?? null,
            'residual_test_done' => $attributes['residual_test_done'] ?? null,
            'residual_test_result' => $attributes['residual_test_result'] ?? null,
            'performed_by' => $actor->id,
            'created_at' => Carbon::now(),
        ]);

        return $this->find($machineId);
    }

    /**
     * Record maintenance, and move the machine's status to match.
     *
     * A failed safety test takes the machine out of service here rather than
     * leaving it for someone to notice: a machine that failed its electrical
     * safety check and is still on the board is the failure this record exists
     * to prevent.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordMaintenance(int $machineId, array $attributes, Staff $actor): stdClass
    {
        return DB::transaction(function () use ($machineId, $attributes, $actor): stdClass {
            $machine = $this->find($machineId);
            $passed = $attributes['passed'] ?? null;

            DB::table('machine_maintenance_logs')->insert([
                'machine_id' => $machine->id,
                'maintenance_type' => $attributes['maintenance_type'],
                'performed_on' => $attributes['performed_on'] ?? $this->calendar->todayString(),
                'run_hours_at' => $attributes['run_hours_at'] ?? $machine->total_run_hours,
                'description' => $attributes['description'] ?? null,
                'parts_replaced' => $attributes['parts_replaced'] ?? null,
                'performed_by' => $attributes['performed_by'] ?? null,
                'performed_by_staff' => $actor->id,
                'cost' => $attributes['cost'] ?? null,
                'passed' => $passed,
                'next_due_on' => $attributes['next_due_on'] ?? null,
                'document_path' => $attributes['document_path'] ?? null,
                'created_at' => Carbon::now(),
            ]);

            $update = [];

            if ($passed !== null && ! $passed) {
                $update['status'] = 'under_repair';
            }

            if (($attributes['maintenance_type'] ?? null) === 'decommission') {
                $update['status'] = 'retired';
            }

            if ($update !== []) {
                DB::table('machines')->where('id', $machine->id)->update($update);
            }

            return $this->find($machineId);
        });
    }

    /**
     * Maintenance that is due or overdue, by the date the last one set.
     *
     * @return list<array<string, mixed>>
     */
    public function maintenanceDue(?string $asOf = null): array
    {
        $date = $asOf ?? $this->calendar->todayString();
        $rows = [];

        // The most recent log per machine, and whether its next_due_on has passed.
        $found = DB::table('machines as m')
            ->join('machine_maintenance_logs as l', 'l.machine_id', '=', 'm.id')
            ->whereNotNull('l.next_due_on')
            ->where('l.next_due_on', '<=', $date)
            ->whereNotIn('m.status', ['retired'])
            ->whereRaw('l.performed_on = (SELECT MAX(x.performed_on) FROM machine_maintenance_logs x WHERE x.machine_id = m.id)')
            ->orderBy('l.next_due_on')
            ->get(['m.asset_tag', 'm.status', 'l.maintenance_type', 'l.performed_on', 'l.next_due_on']);

        foreach ($found as $row) {
            $rows[] = [
                'asset_tag' => $row->asset_tag,
                'status' => $row->status,
                'maintenance_type' => $row->maintenance_type,
                'last_performed_on' => $row->performed_on,
                'due_on' => $row->next_due_on,
            ];
        }

        return $rows;
    }

    public function find(int $machineId): stdClass
    {
        $machine = DB::table('machines')->where('id', $machineId)->first();

        if ($machine === null) {
            throw new DomainRuleException('That machine is not in the register.');
        }

        return $machine;
    }
}
