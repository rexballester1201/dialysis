<?php

declare(strict_types=1);

namespace App\Domain\Ops\Services;

use App\Domain\Core\Models\Staff;
use App\Domain\Core\Services\FacilityCalendar;
use App\Support\Exceptions\DomainRuleException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Consumables: what came in, what went out, and which lot it came from.
 *
 * The balance on a lot is never written by this service. `stock_transactions_ai`
 * adds every signed movement to `stock_lots.qty_on_hand`, so the running total
 * and the movement history cannot disagree -- and a lot cannot be talked below
 * zero, because `sl_qty_ck` refuses it whichever code path tried.
 *
 * Two refusals matter clinically rather than commercially:
 *
 *   quarantined  a recall hold. The lot is physically present and the count says
 *                so, but it must not reach a patient until the hold lifts.
 *   expired      an expired dialyzer or bloodline is not a cost problem.
 *
 * Issuing is first-expiry-first-out: the stock nearest its expiry goes first, so
 * the shelf does not quietly accumulate a lot that will be written off.
 */
final class StockService
{
    public function __construct(private readonly FacilityCalendar $calendar) {}

    /**
     * Receive stock against a lot, creating the lot if it is new.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function receive(array $attributes, Staff $actor): stdClass
    {
        return DB::transaction(function () use ($attributes, $actor): stdClass {
            $itemId = (int) $attributes['item_id'];
            $lotNo = (string) $attributes['lot_no'];
            $receivedOn = (string) ($attributes['received_on'] ?? $this->calendar->todayString());
            $qty = $this->quantity($attributes['qty']);

            if (bccomp($qty, '0', 2) <= 0) {
                throw new DomainRuleException('A receipt must be a positive quantity.');
            }

            $lot = DB::table('stock_lots')
                ->where('item_id', $itemId)
                ->where('lot_no', $lotNo)
                ->where('received_on', $receivedOn)
                ->lockForUpdate()
                ->first();

            if ($lot === null) {
                $lotId = DB::table('stock_lots')->insertGetId([
                    'item_id' => $itemId,
                    'lot_no' => $lotNo,
                    'expiry_date' => $attributes['expiry_date'] ?? null,
                    'supplier_id' => $attributes['supplier_id'] ?? null,
                    'received_on' => $receivedOn,
                    'qty_received' => $qty,
                    // Left at zero: the trigger adds the movement below. Setting
                    // it here would double-count the receipt.
                    'qty_on_hand' => 0,
                    'unit_cost' => $attributes['unit_cost'] ?? null,
                    'invoice_no' => $attributes['invoice_no'] ?? null,
                    'created_at' => Carbon::now(),
                ]);
            } else {
                $lotId = (int) $lot->id;
                DB::table('stock_lots')->where('id', $lotId)->update([
                    'qty_received' => bcadd($this->quantity($lot->qty_received), $qty, 2),
                ]);
            }

            $this->move($lotId, $itemId, 'receipt', $qty, $actor, [
                'reason' => $attributes['reason'] ?? null,
            ]);

            return $this->lot($lotId);
        });
    }

    /**
     * Issue a quantity of an item to a session, drawing from lots in
     * first-expiry-first-out order.
     *
     * @return list<array<string, mixed>> the lots drawn from, and how much of each
     */
    public function issueToSession(int $itemId, string $qty, int $sessionId, ?int $patientId, Staff $actor): array
    {
        $wanted = $this->quantity($qty);

        if (bccomp($wanted, '0', 2) <= 0) {
            throw new DomainRuleException('An issue must be a positive quantity.');
        }

        return DB::transaction(function () use ($itemId, $wanted, $sessionId, $patientId, $actor): array {
            $lots = $this->issuableLots($itemId);

            $remaining = $wanted;
            $drawn = [];

            foreach ($lots as $lot) {
                if (bccomp($remaining, '0', 2) <= 0) {
                    break;
                }

                $available = $this->quantity($lot->qty_on_hand);
                $take = bccomp($available, $remaining, 2) >= 0 ? $remaining : $available;

                if (bccomp($take, '0', 2) <= 0) {
                    continue;
                }

                $this->move($lot->id, $itemId, 'issue_to_session', '-'.$take, $actor, [
                    'session_id' => $sessionId,
                    'patient_id' => $patientId,
                ]);

                $drawn[] = [
                    'lot_no' => $lot->lot_no,
                    'expiry_date' => $lot->expiry_date,
                    'qty' => $take,
                ];

                $remaining = bcsub($remaining, $take, 2);
            }

            if (bccomp($remaining, '0', 2) > 0) {
                // Rolled back with the transaction. A partial issue would leave
                // the count right and the shelf wrong.
                throw new DomainRuleException(sprintf(
                    'Only %s of the %s requested is available in usable lots; %s short.',
                    bcsub($wanted, $remaining, 2),
                    $wanted,
                    $remaining,
                ));
            }

            return $drawn;
        });
    }

    /**
     * Lots that may actually be issued, nearest expiry first.
     *
     * Quarantined and expired lots are excluded rather than merely deprioritised:
     * a recall hold that can be worked around is not a hold.
     *
     * @return list<stdClass>
     */
    public function issuableLots(int $itemId): array
    {
        $rows = [];

        $found = DB::table('stock_lots')
            ->where('item_id', $itemId)
            ->where('is_quarantined', 0)
            ->where('qty_on_hand', '>', 0)
            ->where(function ($query): void {
                // Expired means past the unit's today. Against the UTC date, a lot that
                // expired yesterday stayed issuable until 08:00 in Manila.
                $query->whereNull('expiry_date')->orWhere('expiry_date', '>=', $this->calendar->todayString());
            })
            // FEFO: nearest expiry first, nulls last.
            ->orderByRaw('expiry_date IS NULL, expiry_date')
            ->orderBy('received_on')
            ->lockForUpdate()
            ->get(['id', 'lot_no', 'expiry_date', 'qty_on_hand']);

        foreach ($found as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Write off a lot -- expiry, damage, or a recall that will not be returned.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function writeOff(int $lotId, string $qty, string $moveType, array $attributes, Staff $actor): stdClass
    {
        if (! in_array($moveType, ['expiry_writeoff', 'damage', 'adjustment'], true)) {
            throw new DomainRuleException("'{$moveType}' is not a write-off movement.");
        }

        if (($attributes['reason'] ?? null) === null) {
            throw new DomainRuleException('A write-off needs a reason; it is what an auditor reads.');
        }

        return DB::transaction(function () use ($lotId, $qty, $moveType, $attributes, $actor): stdClass {
            $lot = DB::table('stock_lots')->where('id', $lotId)->lockForUpdate()->first();

            if ($lot === null) {
                throw new DomainRuleException('That stock lot does not exist.');
            }

            $this->move($lotId, (int) $lot->item_id, $moveType, '-'.$this->quantity($qty), $actor, [
                'reason' => $attributes['reason'],
            ]);

            return $this->lot($lotId);
        });
    }

    /** Place or lift a recall hold. */
    public function setQuarantine(int $lotId, bool $quarantined): stdClass
    {
        DB::table('stock_lots')->where('id', $lotId)->update(['is_quarantined' => $quarantined]);

        return $this->lot($lotId);
    }

    /**
     * Record one signed movement. The trigger updates the lot balance.
     *
     * @param  array<string, mixed>  $extra
     */
    private function move(int $lotId, int $itemId, string $moveType, string $qty, Staff $actor, array $extra = []): void
    {
        try {
            DB::table('stock_transactions')->insert($extra + [
                'lot_id' => $lotId,
                'item_id' => $itemId,
                'move_type' => $moveType,
                'qty' => $qty,
                'occurred_at' => Carbon::now(),
                'performed_by' => $actor->id,
                'created_at' => Carbon::now(),
            ]);
        } catch (QueryException $e) {
            // sl_qty_ck: the trigger's UPDATE would have taken the lot below zero.
            if (str_contains($e->getMessage(), 'sl_qty_ck')) {
                throw new DomainRuleException(
                    'That movement would take the lot below zero. Count the shelf before adjusting it.',
                    previous: $e,
                );
            }

            throw $e;
        }
    }

    private function lot(int $lotId): stdClass
    {
        $lot = DB::table('stock_lots')->where('id', $lotId)->first();

        if ($lot === null) {
            throw new DomainRuleException('Stock lot vanished immediately after being written.');
        }

        return $lot;
    }

    /**
     * @return numeric-string
     */
    private function quantity(mixed $value): string
    {
        $string = (string) $value;

        if (! is_numeric($string)) {
            throw new DomainRuleException("Expected a quantity but got: {$string}");
        }

        return $string;
    }
}
