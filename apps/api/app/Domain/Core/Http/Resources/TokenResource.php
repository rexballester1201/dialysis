<?php

declare(strict_types=1);

namespace App\Domain\Core\Http\Resources;

use App\Domain\Core\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\NewAccessToken;

/**
 * The only place the plaintext token is ever exposed.
 *
 * @property-read NewAccessToken $resource
 */
final class TokenResource extends JsonResource
{
    public function __construct(NewAccessToken $token, private readonly Staff $staff)
    {
        parent::__construct($token);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->resource->plainTextToken,
            'abilities' => $this->resource->accessToken->abilities,
            'expires_at' => $this->resource->accessToken->expires_at?->toIso8601String(),
            'device_id' => $this->resource->accessToken->device_id,
            'staff' => [
                // public_id in every payload; the BIGINT id never leaves the server.
                'public_id' => $this->staff->public_id,
                'full_name' => $this->staff->full_name,
            ],
        ];
    }
}
