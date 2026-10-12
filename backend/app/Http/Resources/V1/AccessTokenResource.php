<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Metadata of an issued token (never the secret itself).
 *
 * @mixin PersonalAccessToken
 */
final class AccessTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $current = $request->user()?->currentAccessToken();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'client_type' => $this->client_type->value,
            'device_id' => $this->device_id,
            'abilities' => $this->abilities ?? [],
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'is_current' => $current instanceof PersonalAccessToken && $current->is($this->resource),
        ];
    }
}
