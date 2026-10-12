<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\ClientType;
use App\Enums\UserRole;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TokenLifecycleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function requests_without_a_token_receive_a_json_401(): void
    {
        $this->get('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    #[Test]
    public function the_profile_endpoint_describes_the_user_and_the_presented_token(): void
    {
        $agent = User::factory()->fieldAgent()->create();
        $token = $this->issueToken($agent, ClientType::FieldDevice, 'device-profile-01');

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $agent->id)
            ->assertJsonPath('data.user.organization.id', $agent->organization_id)
            ->assertJsonPath('data.token.device_id', 'device-profile-01')
            ->assertJsonPath('data.token.is_current', true)
            ->assertJsonMissingPath('data.token.token');
    }

    #[Test]
    public function expired_tokens_are_rejected(): void
    {
        Carbon::setTestNow('2026-10-12 08:00:00');
        $coordinator = User::factory()->coordinator()->create();
        $token = $this->issueToken($coordinator, ClientType::WebDashboard);

        Carbon::setTestNow('2026-10-12 19:59:00');
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        $this->forgetAuthenticatedUser();
        Carbon::setTestNow('2026-10-12 20:00:01');
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    #[Test]
    public function refreshing_rotates_the_token_and_revokes_the_old_one(): void
    {
        $agent = User::factory()->fieldAgent()->create();
        $old = $this->issueToken($agent, ClientType::FieldDevice, 'device-rotate-01');

        $response = $this->withToken($old)->postJson('/api/v1/auth/refresh')
            ->assertOk()
            ->assertJsonPath('data.client_type', 'field_device');
        $new = (string) $response->json('data.access_token');

        self::assertNotSame($old, $new);
        self::assertSame('device-rotate-01', PersonalAccessToken::query()->sole()->device_id);

        $this->forgetAuthenticatedUser();
        $this->withToken($old)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->forgetAuthenticatedUser();
        $this->withToken($new)->getJson('/api/v1/auth/me')->assertOk();
    }

    #[Test]
    public function logout_revokes_only_the_presented_token(): void
    {
        $coordinator = User::factory()->coordinator()->create();
        $phone = $this->issueToken($coordinator, ClientType::FieldDevice, 'device-logout-01');
        $browser = $this->issueToken($coordinator, ClientType::WebDashboard);

        $this->withToken($phone)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->forgetAuthenticatedUser();
        $this->withToken($phone)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->forgetAuthenticatedUser();
        $this->withToken($browser)->getJson('/api/v1/auth/me')->assertOk();
    }

    #[Test]
    public function logout_all_revokes_every_token_of_the_user(): void
    {
        $coordinator = User::factory()->coordinator()->create();
        $phone = $this->issueToken($coordinator, ClientType::FieldDevice, 'device-logout-02');
        $this->issueToken($coordinator, ClientType::WebDashboard);

        $this->withToken($phone)->postJson('/api/v1/auth/logout-all')->assertNoContent();

        self::assertSame(0, $coordinator->tokens()->count());
    }

    #[Test]
    public function changing_a_users_role_invalidates_their_tokens(): void
    {
        $agent = User::factory()->fieldAgent()->create();
        $token = $this->issueToken($agent, ClientType::FieldDevice);

        $agent->role = UserRole::Contractor;
        $agent->save();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    #[Test]
    public function deactivating_the_organization_cuts_off_live_tokens(): void
    {
        $agent = User::factory()->fieldAgent()->create();
        $token = $this->issueToken($agent, ClientType::FieldDevice);

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        $agent->organization->update(['is_active' => false]);

        $this->forgetAuthenticatedUser();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        self::assertSame(1, $agent->tokens()->count(), 'Tokens are rejected by the guard, not deleted.');
    }

    #[Test]
    public function users_can_list_and_revoke_their_own_device_tokens(): void
    {
        $coordinator = User::factory()->coordinator()->create();
        $browser = $this->issueToken($coordinator, ClientType::WebDashboard);
        $this->issueToken($coordinator, ClientType::FieldDevice, 'device-lost-phone');

        $listing = $this->withToken($browser)->getJson('/api/v1/auth/tokens')->assertOk()->assertJsonCount(2, 'data');
        $lostPhoneId = collect($listing->json('data'))->firstWhere('device_id', 'device-lost-phone')['id'];

        $this->withToken($browser)->deleteJson("/api/v1/auth/tokens/{$lostPhoneId}")->assertNoContent();

        self::assertSame([null], $coordinator->tokens()->pluck('device_id')->all());
    }

    #[Test]
    public function users_cannot_revoke_someone_elses_token(): void
    {
        $coordinator = User::factory()->coordinator()->create();
        $colleague = User::factory()->fieldAgent()->for($coordinator->organization)->create();
        $browser = $this->issueToken($coordinator, ClientType::WebDashboard);
        $this->issueToken($colleague, ClientType::FieldDevice);
        $colleagueTokenId = $colleague->tokens()->sole()->id;

        $this->withToken($browser)->deleteJson("/api/v1/auth/tokens/{$colleagueTokenId}")->assertNotFound();

        self::assertSame(1, $colleague->tokens()->count());
    }
}
