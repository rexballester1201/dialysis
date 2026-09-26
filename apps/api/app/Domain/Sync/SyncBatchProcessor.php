<?php

declare(strict_types=1);

namespace App\Domain\Sync;

use App\Domain\Core\Models\Staff;
use App\Domain\Sync\Handlers\OperationHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies one batch of offline operations from a bedside tablet.
 *
 * Guarantees
 * ----------
 *  1. Batch-level idempotency: a replayed batch_uuid returns the stored
 *     result verbatim without touching any clinical table. Networks retry;
 *     nurses re-tap; neither may create a second set of vitals.
 *  2. Operation-level idempotency: every operation carries a client-minted
 *     ULID persisted in the target row's client_uuid column, which has a
 *     UNIQUE index. A duplicate insert is reported, not applied twice.
 *  3. Partial success: one bad operation does not sink the batch. Each
 *     operation runs in its own transaction and reports its own outcome, so
 *     a nurse never loses four hours of charting to one malformed row.
 */
final class SyncBatchProcessor
{
    /** @param  array<string, OperationHandler>  $handlers keyed by operation type */
    public function __construct(private readonly array $handlers) {}

    /**
     * @param  list<array<string, mixed>>  $operations
     * @return array<string, mixed>
     */
    public function process(string $batchUuid, string $deviceId, Staff $actor, array $operations): array
    {
        if ($existing = $this->replayOf($batchUuid)) {
            return $existing + ['replayed' => true];
        }

        $results = [];

        foreach ($operations as $index => $operation) {
            $results[] = $this->apply($operation, $index, $actor);
        }

        $summary = [
            'batch_uuid' => $batchUuid,
            'received_at' => now()->toIso8601String(),
            'results' => $results,
        ];

        DB::table('sync_batches')->insert([
            'batch_uuid' => $batchUuid,
            'device_id' => $deviceId,
            'staff_id' => $actor->id,
            'received_at' => now(),
            'operation_count' => count($operations),
            'applied_count' => $this->countBy($results, 'applied'),
            'rejected_count' => $this->countBy($results, 'rejected') + $this->countBy($results, 'conflict'),
            'result' => json_encode($summary, JSON_THROW_ON_ERROR),
        ]);

        return $summary + ['replayed' => false];
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function apply(array $operation, int $index, Staff $actor): array
    {
        $opUuid = $operation['op_uuid'] ?? "index-{$index}";
        $type = $operation['type'] ?? null;
        $handler = $this->handlers[$type] ?? null;

        if ($handler === null) {
            return $this->result($opUuid, 'rejected', "Unknown operation type: {$type}");
        }

        try {
            // Each operation is its own transaction: partial success is the point.
            return DB::transaction(fn () => $handler->handle($operation, $actor, $opUuid));
        } catch (Throwable $e) {
            // Never leak an exception into the batch response; the tablet must
            // always receive a verdict for every operation it sent.
            Log::warning('sync operation failed', [
                'op_uuid' => $opUuid, 'type' => $type, 'error' => $e->getMessage(),
            ]);

            return $this->result($opUuid, 'rejected', $e->getMessage());
        }
    }

    /** @return array<string, mixed>|null */
    private function replayOf(string $batchUuid): ?array
    {
        $row = DB::table('sync_batches')->where('batch_uuid', $batchUuid)->first();

        return $row === null ? null : json_decode($row->result, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param  list<array<string, mixed>>  $results */
    private function countBy(array $results, string $status): int
    {
        return count(array_filter($results, fn ($r) => $r['status'] === $status));
    }

    /** @return array<string, mixed> */
    private function result(string $opUuid, string $status, ?string $message = null): array
    {
        return array_filter([
            'op_uuid' => $opUuid,
            'status' => $status,
            'message' => $message,
        ], fn ($v) => $v !== null);
    }
}
