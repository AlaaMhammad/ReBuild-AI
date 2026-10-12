<?php

declare(strict_types=1);

namespace Tests;

use App\Enums\ClientType;
use App\Models\User;
use App\Services\Auth\TokenIssuer;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Issues a real Sanctum token through the production issuer and returns its plain-text secret.
     */
    protected function issueToken(User $user, ClientType $clientType, ?string $deviceId = null): string
    {
        $deviceId ??= $clientType === ClientType::FieldDevice ? 'test-device-'.$user->id : null;

        return $this->app->make(TokenIssuer::class)
            ->issue($user, $clientType, 'phpunit', $deviceId)
            ->plainTextToken;
    }

    /**
     * Guards cache the resolved user for the lifetime of the application
     * instance; drop that cache so the next request re-authenticates its token.
     */
    protected function forgetAuthenticatedUser(): void
    {
        $this->app['auth']->forgetGuards();
    }

    protected function tenant(): TenantContext
    {
        return $this->app->make(TenantContext::class);
    }
}
