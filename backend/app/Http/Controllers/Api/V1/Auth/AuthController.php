<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\V1\AccessTokenResource;
use App\Http\Resources\V1\UserResource;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Auth\TokenIssuer;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

final class AuthController extends Controller
{
    public function __construct(
        private readonly TokenIssuer $tokens,
    ) {}

    /**
     * POST /api/v1/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $email = mb_strtolower(trim((string) $request->validated('email')));
        $password = (string) $request->validated('password');
        $clientType = $request->clientType();

        $user = User::query()->with('organization')->where('email', $email)->first();

        if ($user === null) {
            // Spend the same bcrypt cost as a real check so response timing
            // does not reveal whether the account exists.
            Hash::make($password);

            throw ValidationException::withMessages(['email' => [__('auth.failed')]]);
        }

        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['email' => [__('auth.failed')]]);
        }

        if (! $user->canAuthenticateVia($clientType)) {
            throw new AuthorizationException('This account is disabled or is not permitted to sign in from this client.');
        }

        // Quiet saves: neither a rehash nor a login timestamp may trigger the
        // token-revocation hook on User.
        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $password])->saveQuietly();
        }

        $user->forceFill(['last_login_at' => CarbonImmutable::now()])->saveQuietly();

        $token = $this->tokens->issue(
            user: $user,
            clientType: $clientType,
            deviceName: (string) $request->validated('device_name'),
            deviceId: $request->deviceId(),
            ipAddress: $request->ip(),
        );

        return $this->tokenResponse($user, $token, Response::HTTP_CREATED);
    }

    /**
     * GET /api/v1/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $user->loadMissing('organization');

        return response()->json([
            'data' => [
                'user' => UserResource::make($user),
                'token' => AccessTokenResource::make($this->currentToken($request)),
            ],
        ]);
    }

    /**
     * POST /api/v1/auth/refresh
     *
     * Rotates the presented token. The response carries the only copy of the
     * new secret; the presented token is revoked atomically.
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $user->loadMissing('organization');

        $current = $this->currentToken($request);

        if (! $user->canAuthenticateVia($current->client_type)) {
            $this->tokens->revoke($current);

            throw new AuthorizationException('This account is disabled or is not permitted to sign in from this client.');
        }

        $token = $this->tokens->rotate($user, $current, $request->ip());

        return $this->tokenResponse($user, $token, Response::HTTP_OK);
    }

    /**
     * POST /api/v1/auth/logout
     */
    public function logout(Request $request): Response
    {
        $this->tokens->revoke($this->currentToken($request));

        return response()->noContent();
    }

    /**
     * POST /api/v1/auth/logout-all
     */
    public function logoutAll(Request $request): Response
    {
        $this->tokens->revokeAll($this->user($request));

        return response()->noContent();
    }

    private function tokenResponse(User $user, NewAccessToken $token, int $status): JsonResponse
    {
        /** @var PersonalAccessToken $accessToken */
        $accessToken = $token->accessToken;

        return response()->json([
            'data' => [
                'token_type' => 'Bearer',
                'access_token' => $token->plainTextToken,
                'expires_at' => $accessToken->expires_at?->toIso8601String(),
                'client_type' => $accessToken->client_type->value,
                'abilities' => $accessToken->abilities ?? [],
                'user' => UserResource::make($user),
            ],
        ], $status)->withHeaders([
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }

    private function currentToken(Request $request): PersonalAccessToken
    {
        $token = $this->user($request)->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            throw new AuthenticationException('A bearer token is required for this endpoint.');
        }

        return $token;
    }
}
