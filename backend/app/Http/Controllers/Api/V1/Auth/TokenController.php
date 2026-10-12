<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AccessTokenResource;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Auth\TokenIssuer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Self-service management of the caller's own sessions/devices, e.g. to
 * revoke a phone that has been lost without waiting for an administrator.
 */
final class TokenController extends Controller
{
    public function __construct(
        private readonly TokenIssuer $tokens,
    ) {}

    /**
     * GET /api/v1/auth/tokens
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $tokens = $this->user($request)
            ->tokens()
            ->unexpired()
            ->orderByDesc('created_at')
            ->get();

        return AccessTokenResource::collection($tokens);
    }

    /**
     * DELETE /api/v1/auth/tokens/{tokenId}
     */
    public function destroy(Request $request, int $tokenId): Response
    {
        /** @var PersonalAccessToken $token */
        $token = $this->user($request)->tokens()->whereKey($tokenId)->firstOrFail();

        $this->tokens->revoke($token);

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
