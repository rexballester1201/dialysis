<?php

declare(strict_types=1);

namespace App\Domain\Core\Http\Resources;

use App\Domain\Core\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Patient $resource
 */
final class PatientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // The BIGINT id never leaves the server; clients only ever see the ULID.
            'public_id' => $this->resource->public_id,
            'mrn' => $this->resource->mrn,
            'first_name' => $this->resource->first_name,
            'middle_name' => $this->resource->middle_name,
            'last_name' => $this->resource->last_name,
            'suffix' => $this->resource->suffix,
            'full_name' => $this->resource->full_name,
            'birth_date' => $this->resource->birth_date->toDateString(),
            'sex' => $this->resource->sex,
            'blood_type' => $this->resource->blood_type,
            'status' => $this->resource->status,
            // Derived through v_patient_cohort so PHP and SQL cannot disagree
            // about which chair this patient may occupy.
            'cohort' => $this->resource->cohort()->value,
            'first_dialysis_date' => $this->resource->first_dialysis_date?->toDateString(),
        ];
    }
}
