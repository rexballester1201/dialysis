<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Http\Resources;

use App\Domain\Clinical\Models\SessionEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read SessionEvent $resource
 */
final class SessionEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'occurred_at' => $this->resource->occurred_at->toIso8601String(),
            'event_code' => $this->resource->event_code,
            'severity' => $this->resource->severity,
            'description' => $this->resource->description,
            'intervention' => $this->resource->intervention,
            'outcome' => $this->resource->outcome,
        ];
    }
}
