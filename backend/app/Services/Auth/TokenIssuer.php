<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\ClientType;
use App\Enums\TokenAbility;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Sanctum\NewAccessToken;

/**
 * Issues, rotates and revokes Sanctum tokens according to the platform's
 * RBAC rules: abilities = role abilities ∩ client-type ceiling, lifetime set
 * by client type, at most one live token per field device.
 */
final class TokenIssuer
{
    public function issue(
        User $user,
        ClientType $clientType,
        string $deviceName,
        ?string $deviceId = null,
        ?string $ipAddress = null,
    ): NewAccessToken {
        if (! $user->role->canUseClient($clientType)) {
            throw new InvalidArgumentException(
                "Role [{$user->role->value}] may not authenticate from client type [{$clientType->value}]."
            );
        }

        if ($clientType->requiresDeviceId() && ($deviceId === null || $deviceId === '')) {
            throw new InvalidArgumentException('Field device tokens must be bound to a device id.');
        }

        $abilities = array_map(
            static fn (TokenAbility $ability): string => $ability->value,
            $user->role->abilitiesFor($clientType),
        );

        $expiresAt = CarbonImmutable::now()->addMinutes($clientType->tokenTtlMinutes());

        return DB::transaction(function () use ($user, $clientType, $deviceName, $deviceId, $ipAddress, $abilities, $expiresAt): NewAccessToken {
            if ($deviceId !== null && $deviceId !== '') {
                // A device holds exactly one live token: re-login on the same
                // device replaces the previous credential.
                $user->tokens()->where('device_id', $deviceId)->delete();
            }

            $plainTextToken = $user->generateTokenString();

            /** @var PersonalAccessToken $token */
            $token = $user->tokens()->create([
                'name' => mb_substr($deviceName, 0, 255),
                'token' => hash('sha256', $plainTextToken),
                'abilities' => $abilities,
                'client_type' => $clientType,
                'device_id' => $deviceId,
                'created_ip' => $ipAddress,
                'expires_at' => $expiresAt,
            ]);

            $this->pruneExcessTokens($user);

            return new NewAccessToken($token, $token->getKey().'|'.$plainTextToken);
        });
    }

    /**
     * Replaces the given token with a fresh one carrying abilities recomputed
     * from the user's current role. The old token stops working immediately.
     */
    public function rotate(User $user, PersonalAccessToken $current, ?string $ipAddress = null): NewAccessToken
    {
        return DB::transaction(function () use ($user, $current, $ipAddress): NewAccessToken {
            $current->delete();

            return $this->issue($user, $current->client_type, $current->name, $current->device_id, $ipAddress);
        });
    }

    public function revoke(PersonalAccessToken $token): void
    {
        $token->delete();
    }

    /**
     * @return int Number of tokens revoked.
     */
    public function revokeAll(User $user): int
    {
        return $user->tokens()->delete();
    }

    private function pruneExcessTokens(User $user): void
    {
        $limit = (int) config('rebuild.auth.max_active_tokens_per_user');

        if ($limit < 1) {
            return;
        }

        $excessIds = $user->tokens()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->pluck('id')
            ->slice($limit)
            ->values();

        if ($excessIds->isNotEmpty()) {
            $user->tokens()->whereIn('id', $excessIds)->delete();
        }
    }
}
