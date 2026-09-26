<?php

declare(strict_types=1);

namespace App\Domain\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use stdClass;

/**
 * One row of the claims list.
 *
 * A resource rather than the raw row for the same reason as everywhere else:
 * the list used to go out as Laravel's flat paginator with column aliases for
 * keys, the one list in the API without the data/links/meta envelope the typed
 * client parses.
 *
 * @property stdClass $resource
 */
final class ClaimSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            'claim_no' => $row->claim_no,
            'status' => $row->status,
            'patient' => [
                'public_id' => $row->patient_public_id,
                'mrn' => $row->mrn,
                'full_name' => $row->full_name,
            ],
            'program_code' => $row->program_code,
            'service_from' => (string) $row->service_from,
            'service_to' => (string) $row->service_to,
            'session_count' => (int) $row->session_count,
            // DECIMAL(12,2) arrives as a string and leaves as one.
            'amount_claimed' => $row->amount_claimed,
            'amount_approved' => $row->amount_approved,
            'amount_paid' => $row->amount_paid,
        ];
    }
}
