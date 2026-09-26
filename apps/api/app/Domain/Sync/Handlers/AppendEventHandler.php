<?php

declare(strict_types=1);

namespace App\Domain\Sync\Handlers;

use App\Domain\Clinical\Exceptions\DuplicateObservationException;
use App\Domain\Clinical\Exceptions\SessionLockedException;
use App\Domain\Clinical\Models\TreatmentSession;
use App\Domain\Clinical\Services\FlowSheetService;
use App\Domain\Core\Models\Staff;

/**
 * Intra-dialytic events -- a hypotensive episode, cramps, a clotted circuit --
 * charted on a tablet that may have been offline when they happened.
 *
 * Like vitals, events are append-only and carry a device-minted ULID in
 * `session_events.client_uuid`, which has a UNIQUE index. A replayed batch
 * reports `duplicate` rather than charting the episode twice.
 *
 * The write is FlowSheetService's, the same one the online endpoint uses. An
 * event that arrives six hours late is subject to exactly the rules an event
 * charted at the desk would be -- including the refusal of an unrecognised
 * event code, which is why a typo comes back as `rejected` with a message a
 * nurse can act on rather than being stored as a category no report counts.
 */
final class AppendEventHandler implements OperationHandler
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
            $event = $this->flowSheet->appendEvent($session, $payload, $actor, $opUuid);
        } catch (SessionLockedException) {
            // Signed while this device was offline. The nurse's event is not
            // discarded silently -- the tablet is told, and the correction goes
            // through the amendment path.
            return [
                'op_uuid' => $opUuid,
                'status' => 'conflict',
                'message' => 'Session was signed and locked while this device was offline.',
                'server_state' => ['locked_at' => $session->locked_at?->toIso8601String()],
            ];
        } catch (DuplicateObservationException) {
            return ['op_uuid' => $opUuid, 'status' => 'duplicate'];
        }

        return ['op_uuid' => $opUuid, 'status' => 'applied', 'server_id' => $event->id];
    }
}
