<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Http\Resources;

use App\Domain\Clinical\Models\SerologyResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read SerologyResult $resource
 */
final class SerologyResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'marker' => $this->resource->marker,
            'result' => $this->resource->result,
            'titre' => $this->resource->titre,
            'specimen_date' => $this->resource->specimen_date->toDateString(),
            'resulted_on' => $this->resource->resulted_on?->toDateString(),
            'lab_name' => $this->resource->lab_name,
            'recorded_at' => $this->resource->recorded_at->toIso8601String(),
        ];
    }
}
