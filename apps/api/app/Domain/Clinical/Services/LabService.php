<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Models\LabOrder;
use App\Domain\Clinical\Models\LabResult;
use App\Domain\Clinical\Models\LabTestRef;
use App\Domain\Core\Models\Patient;
use App\Domain\Core\Models\Staff;
use App\Domain\Core\Services\FacilityCalendar;
use App\Support\Exceptions\DomainRuleException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Monthly bloods: ordering a panel, and filing what comes back.
 *
 * Two things this service will not do, both for the same reason.
 *
 * It does not accept an abnormal flag from a client. The flag is derived from
 * lab_test_refs at the moment of filing, because a stored flag that disagrees
 * with its own value and range is a flag somebody will act on. Per the prime
 * directive, anything the server can compute, the server computes.
 *
 * It does not invent the thresholds for LL and HH. The schema permits them and
 * a laboratory feed may supply them, but "critically abnormal" is a clinical
 * cut-off that is not in the reference data, and guessing one would put a
 * fabricated number in front of a clinician. Flags this service computes are
 * L, H or N and nothing else; LL and HH survive only when a lab sent them.
 */
final class LabService
{
    public function __construct(private readonly FacilityCalendar $calendar) {}

    /** Columns a caller may set on a result. Everything else is the server's. */
    private const RESULT_COLUMNS = [
        'test_code', 'value_num', 'value_text', 'unit',
        'specimen_date', 'timing', 'resulted_at', 'source',
    ];

    /**
     * The catalogue, in the order a panel is read.
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalogue(?string $panel = null): array
    {
        return LabTestRef::query()
            ->when($panel !== null, fn ($query) => $query->where('panel', $panel))
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get()
            ->map(fn (LabTestRef $ref): array => $ref->toArray())
            ->values()
            ->all();
    }

    /**
     * Order a panel.
     *
     * The panel is a grouping in lab_test_refs, not a list of codes copied onto
     * the order -- so a test added to the monthly panel next year appears on
     * next year's orders without anyone reissuing anything.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function order(Patient $patient, array $attributes, Staff $actor): LabOrder
    {
        $panel = $attributes['panel'] ?? null;

        if ($panel !== null && ! LabTestRef::query()->where('panel', $panel)->exists()) {
            throw new DomainRuleException(
                "No tests are configured for the '{$panel}' panel, so ordering it would draw blood for nothing."
            );
        }

        return LabOrder::create([
            'patient_id' => $patient->id,
            'ordered_on' => $attributes['ordered_on'] ?? $this->calendar->todayString(),
            'panel' => $panel,
            'ordered_by' => $actor->id,
            'status' => 'ordered',
            'lab_name' => $attributes['lab_name'] ?? null,
        ]);
    }

    /**
     * Move an order along.
     *
     * ordered -> collected -> resulted, or cancelled from either of the first
     * two. A resulted order is closed: reopening it would let a second set of
     * results attach to a panel a clinician has already read.
     */
    public function transition(LabOrder $order, string $to, Staff $actor): LabOrder
    {
        $allowed = [
            'ordered' => ['collected', 'cancelled'],
            'collected' => ['resulted', 'cancelled'],
            'resulted' => [],
            'cancelled' => [],
        ];

        $from = (string) $order->status;

        if (! in_array($to, $allowed[$from] ?? [], true)) {
            throw new DomainRuleException(
                $allowed[$from] === []
                    ? "This order is already {$from} and cannot be changed."
                    : sprintf('An order that is %s can only become %s.', $from, implode(' or ', $allowed[$from])),
            );
        }

        $order->forceFill(array_filter([
            'status' => $to,
            'collected_at' => $to === 'collected' ? Carbon::now() : null,
        ], fn (mixed $v): bool => $v !== null))->save();

        return $order->refresh();
    }

    /**
     * File a batch of results.
     *
     * Each row is adjudicated on its own and the batch reports per-row outcomes,
     * for the same reason a sync batch does: a lab panel is a dozen values typed
     * from one page, and refusing all twelve because one was mistyped means the
     * other eleven get retyped. The transaction wraps the batch so a caller
     * cannot end up with a half-filed panel, but each row's verdict is its own.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{filed: int, duplicates: int, rejected: int, results: array<int, array<string, mixed>>}
     */
    public function fileResults(Patient $patient, array $rows, Staff $actor, ?LabOrder $order = null): array
    {
        if ($order !== null && (int) $order->patient_id !== (int) $patient->id) {
            throw new DomainRuleException('That lab order belongs to a different patient.');
        }

        $outcomes = [];
        $filed = 0;

        foreach ($rows as $index => $row) {
            $verdict = $this->fileOne($patient, $row, $actor, $order);
            $verdict['index'] = $index;
            $outcomes[] = $verdict;

            // Only a row that actually wrote counts. A duplicate is neither an
            // error nor a write, and counting it would report "2 filed" for one
            // stored result -- and would close the order below on a batch that
            // stored nothing at all.
            if ($verdict['status'] === 'filed') {
                $filed++;
            }
        }

        // An order whose results are in is resulted. Only when something landed:
        // a batch that was rejected outright leaves the order where it was.
        if ($order !== null && $filed > 0 && $order->status === 'collected') {
            $this->transition($order, 'resulted', $actor);
        }

        $count = fn (string $status): int => count(array_filter($outcomes, fn (array $o): bool => $o['status'] === $status));

        return [
            'filed' => $filed,
            'duplicates' => $count('duplicate'),
            'rejected' => $count('rejected'),
            'results' => $outcomes,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function fileOne(Patient $patient, array $row, Staff $actor, ?LabOrder $order): array
    {
        $code = (string) ($row['test_code'] ?? '');
        $ref = LabTestRef::query()->find($code);

        if ($ref === null) {
            return [
                'test_code' => $code,
                'status' => 'rejected',
                // The same reasoning as session event codes: a code nothing
                // recognises becomes a result no report ever counts.
                'message' => "'{$code}' is not a test in the catalogue.",
            ];
        }

        $values = array_intersect_key($row, array_flip(self::RESULT_COLUMNS));

        if (($values['value_num'] ?? null) === null && ($values['value_text'] ?? null) === null) {
            return [
                'test_code' => $code,
                'status' => 'rejected',
                'message' => "{$ref->name} has no value. A result with nothing in it is not a result.",
            ];
        }

        $values['patient_id'] = $patient->id;
        $values['order_id'] = $order?->id;
        $values['entered_by'] = $actor->id;
        $values['unit'] = $values['unit'] ?? $ref->unit;
        $values['source'] = $values['source'] ?? 'manual';
        $values['resulted_at'] = $values['resulted_at'] ?? Carbon::now();

        // Derived here, never accepted from the caller.
        $values['abnormal_flag'] = $this->flagFor($ref, $values['value_num'] ?? null);

        try {
            $result = DB::transaction(fn (): LabResult => LabResult::create($values));
        } catch (QueryException $e) {
            if ($this->isDuplicate($e)) {
                return [
                    'test_code' => $code,
                    'status' => 'duplicate',
                    'message' => sprintf(
                        'A %s for %s is already filed. Correct the existing one rather than adding a second.',
                        $ref->name,
                        (string) ($values['specimen_date'] ?? 'that date'),
                    ),
                ];
            }

            throw $e;
        }

        return [
            'test_code' => $code,
            'status' => 'filed',
            'id' => $result->id,
            'abnormal_flag' => $result->abnormal_flag,
            'on_target' => $this->onTarget($ref, $values['value_num'] ?? null),
        ];
    }

    /**
     * L, H or N against the laboratory reference interval.
     *
     * Null when the test has no interval (BUN, creatinine and Kt/V are reported
     * without one here) or when the value is not numeric -- a flag on a value
     * nothing can be compared against would be decoration.
     */
    public function flagFor(LabTestRef $ref, mixed $value): ?string
    {
        if ($value === null || ! is_numeric((string) $value)) {
            return null;
        }

        $low = $ref->ref_low;
        $high = $ref->ref_high;

        if ($low === null && $high === null) {
            return null;
        }

        // bccomp on strings: these are DECIMAL(12,4) and a boundary comparison
        // is the only comparison that matters.
        $v = (string) $value;

        if ($low !== null && bccomp($v, (string) $low, 4) < 0) {
            return 'L';
        }

        if ($high !== null && bccomp($v, (string) $high, 4) > 0) {
            return 'H';
        }

        return 'N';
    }

    /**
     * Whether the value sits in the range a dialysis patient should be in.
     *
     * Deliberately separate from the abnormal flag. Haemoglobin 11 g/dL is
     * flagged L against the general reference interval of 12-16 and is squarely
     * on target for a dialysed patient; a screen that shows only the first
     * number teaches staff to ignore red.
     *
     * Null means the test has no target, not that the value passed.
     */
    public function onTarget(LabTestRef $ref, mixed $value): ?bool
    {
        if ($value === null || ! is_numeric((string) $value)) {
            return null;
        }

        $low = $ref->target_low;
        $high = $ref->target_high;

        if ($low === null && $high === null) {
            return null;
        }

        $v = (string) $value;

        if ($low !== null && bccomp($v, (string) $low, 4) < 0) {
            return false;
        }

        if ($high !== null && bccomp($v, (string) $high, 4) > 0) {
            return false;
        }

        return true;
    }

    /**
     * A patient's results, newest specimen first, with the ranges attached.
     *
     * The ranges travel with the result rather than being looked up by the
     * client, so a screen cannot pair this month's value with next year's
     * reference interval.
     *
     * @return array<int, array<string, mixed>>
     */
    public function resultsFor(Patient $patient, ?string $testCode = null, int $limit = 200): array
    {
        $refs = LabTestRef::query()->get()->keyBy('code');

        return LabResult::query()
            ->where('patient_id', $patient->id)
            ->when($testCode !== null, fn ($query) => $query->where('test_code', $testCode))
            ->orderByDesc('specimen_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (LabResult $result) use ($refs): array {
                $ref = $refs->get($result->test_code);

                return [
                    'id' => $result->id,
                    'test_code' => $result->test_code,
                    'name' => $ref?->name,
                    'panel' => $ref?->panel,
                    'value_num' => $result->value_num,
                    'value_text' => $result->value_text,
                    'unit' => $result->unit ?? $ref?->unit,
                    'specimen_date' => $result->specimen_date->toDateString(),
                    'timing' => $result->timing,
                    'abnormal_flag' => $result->abnormal_flag,
                    'on_target' => $ref === null ? null : $this->onTarget($ref, $result->value_num),
                    'ref_low' => $ref?->ref_low,
                    'ref_high' => $ref?->ref_high,
                    'target_low' => $ref?->target_low,
                    'target_high' => $ref?->target_high,
                    'source' => $result->source,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The most recent value for every test, which is what a chart shows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function latestFor(Patient $patient): array
    {
        $seen = [];
        $latest = [];

        // resultsFor() is already newest-first, so the first sighting of a code
        // is the current one.
        foreach ($this->resultsFor($patient) as $result) {
            $code = (string) $result['test_code'];

            if (isset($seen[$code])) {
                continue;
            }

            $seen[$code] = true;
            $latest[] = $result;
        }

        return $latest;
    }

    private function isDuplicate(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }
}
