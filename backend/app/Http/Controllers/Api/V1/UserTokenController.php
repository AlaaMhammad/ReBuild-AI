<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Administrative revocation of another user's tokens (lost or seized device,
 * staff departure). Route-model binding runs under the tenant scope, so a
 * user from another organization resolves to 404.
 */
final class UserTokenController extends Controller
{
    public function __construct(
        private readonly TokenIssuer $tokens,
    ) {}

    /**
     * DELETE /api/v1/users/{user}/tokens
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $revoked = $this->tokens->revokeAll($user);

        Log::notice('auth.tokens.revoked_by_admin', [
            'target_user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'actor_user_id' => $request->user()?->getAuthIdentifier(),
            'revoked_count' => $revoked,
        ]);

        return response()->json(['data' => ['revoked_count' => $revoked]]);
    }
}
