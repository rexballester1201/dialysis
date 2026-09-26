<?php

declare(strict_types=1);

namespace App\Domain\Sync\Handlers;

use App\Domain\Clinical\Exceptions\DuplicateObservationException;
use App\Domain\Clinical\Exceptions\SessionLockedException;
use App\Domain\Clinical\Models\TreatmentSession;
use App\Domain\Clinical\Services\FlowSheetService;
use App\Domain\Core\Models\Staff;

/**
 * Intra-dialytic observations are append-only and keyed by
 * (session_id, recorded_at). That makes them conflict-free by construction:
 * two devices charting the same session cannot produce a merge conflict,
 * only a duplicate, which the unique index turns into a no-op.
 *
 * The write itself is FlowSheetService's, the same one the online endpoints use.
 * This class only translates between the sync protocol's verdicts and that
 * service -- a vital that arrives from a tablet six hours late is subject to
 * exactly the rules a vital charted at the desk would be.
 */
final class AppendVitalHandler implements OperationHandler
{
    public function __construct(private readonly FlowSheetService $flowSheet) {}

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    public function handle(array $operation, Staff $actor, string $opUuid): array
    {
        $payload = $operation['payload'] ?? [];
        $session = TreatmentSession::where('public_id', $payload['session_public_id'] ?? '')->first();

        if ($session === null) {
            return ['op_uuid' => $opUuid, 'status' => 'rejected', 'message' => 'Unknown session'];
        }

        try {
            $vital = $this->flowSheet->appendVital($session, $payload, $actor, $opUuid);
        } catch (SessionLockedException $e) {
            // The record was signed while this device was offline. The nurse's
            // observation is not discarded silently -- the tablet is told, and
            // the correction goes through the amendment path.
            return [
                'op_uuid' => $opUuid,
                'status' => 'conflict',
                'message' => 'Session was signed and locked while this device was offline.',
                'server_state' => ['locked_at' => $session->locked_at?->toIso8601String()],
            ];
        } catch (DuplicateObservationException) {
            return ['op_uuid' => $opUuid, 'status' => 'duplicate'];
        }

        return ['op_uuid' => $opUuid, 'status' => 'applied', 'server_id' => $vital->id];
    }
}
