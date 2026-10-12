<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role->value,
            'locale' => $this->locale,
            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'organization' => OrganizationResource::make($this->whenLoaded('organization')),
        ];
    }
}
