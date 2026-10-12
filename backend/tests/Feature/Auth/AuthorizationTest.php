<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\ClientType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_admin_can_revoke_every_token_of_a_user_in_their_organization(): void
    {
        $admin = User::factory()->admin()->create();
        $agent = User::factory()->fieldAgent()->for($admin->organization)->create();
        $agentToken = $this->issueToken($agent, ClientType::FieldDevice);

        $this->withToken($this->issueToken($admin, ClientType::WebDashboard))
            ->deleteJson("/api/v1/users/{$agent->id}/tokens")
            ->assertOk()
            ->assertJsonPath('data.revoked_count', 1);

        $this->forgetAuthenticatedUser();
        $this->withToken($agentToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    #[Test]
    public function a_token_without_the_users_manage_ability_is_forbidden(): void
    {
        $coordinator = User::factory()->coordinator()->create();
        $agent = User::factory()->fieldAgent()->for($coordinator->organization)->create();
        $this->issueToken($agent, ClientType::FieldDevice);

        $this->withToken($this->issueToken($coordinator, ClientType::WebDashboard))
            ->deleteJson("/api/v1/users/{$agent->id}/tokens")
            ->assertForbidden();

        self::assertSame(1, $agent->tokens()->count());
    }

    #[Test]
    public function an_admin_cannot_reach_users_of_another_organization(): void
    {
        $admin = User::factory()->admin()->create();
        $foreignAgent = User::factory()->fieldAgent()->for(Organization::factory())->create();
        $this->issueToken($foreignAgent, ClientType::FieldDevice);

        $this->withToken($this->issueToken($admin, ClientType::WebDashboard))
            ->deleteJson("/api/v1/users/{$foreignAgent->id}/tokens")
            ->assertNotFound();

        self::assertSame(1, $foreignAgent->tokens()->count());
    }

    #[Test]
    public function the_tenant_context_is_released_after_each_request(): void
    {
        $admin = User::factory()->admin()->create();

        $this->withToken($this->issueToken($admin, ClientType::WebDashboard))
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        self::assertFalse($this->tenant()->isSet());
    }
}
