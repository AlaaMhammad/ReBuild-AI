<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\ClientType;
use App\Enums\TokenAbility;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class LoginTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE_ID = 'android-7f3a9c2e41b8';

    #[Test]
    public function a_field_agent_obtains_a_device_bound_token_with_field_abilities(): void
    {
        Carbon::setTestNow('2026-10-12 08:00:00');
        $agent = User::factory()->fieldAgent()->create(['email' => 'agent@example.org']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'Agent@Example.org',
            'password' => UserFactory::DEFAULT_PASSWORD,
            'client_type' => ClientType::FieldDevice->value,
            'device_name' => 'Samsung A14 – Khan Yunis team',
            'device_id' => self::DEVICE_ID,
        ]);

        $response->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.client_type', 'field_device')
            ->assertJsonPath('data.user.id', $agent->id)
            ->assertJsonPath('data.user.role', 'field_agent')
            ->assertJsonPath('data.expires_at', '2026-11-11T08:00:00+00:00')
            ->assertJsonPath('data.abilities', array_map(
                static fn (TokenAbility $ability): string => $ability->value,
                UserRole::FieldAgent->abilitiesFor(ClientType::FieldDevice),
            ));

        self::assertStringStartsWith('rbai_', explode('|', (string) $response->json('data.access_token'))[1]);

        $token = PersonalAccessToken::query()->sole();
        self::assertSame(self::DEVICE_ID, $token->device_id);
        self::assertSame(ClientType::FieldDevice, $token->client_type);
        self::assertNotNull($agent->refresh()->last_login_at);
    }

    #[Test]
    public function a_coordinator_web_token_is_short_lived_and_carries_review_abilities(): void
    {
        Carbon::setTestNow('2026-10-12 08:00:00');
        $coordinator = User::factory()->coordinator()->create();

        $this->postJson('/api/v1/auth/login', $this->credentials($coordinator, ClientType::WebDashboard))
            ->assertCreated()
            ->assertJsonPath('data.expires_at', '2026-10-12T20:00:00+00:00')
            ->assertJsonFragment(['abilities' => array_map(
                static fn (TokenAbility $ability): string => $ability->value,
                UserRole::Coordinator->abilities(),
            )]);
    }

    #[Test]
    public function field_device_logins_require_a_device_id(): void
    {
        $agent = User::factory()->fieldAgent()->create();

        $this->postJson('/api/v1/auth/login', [...$this->credentials($agent, ClientType::FieldDevice), 'device_id' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('device_id');
    }

    #[Test]
    public function wrong_passwords_and_unknown_accounts_are_indistinguishable(): void
    {
        $agent = User::factory()->fieldAgent()->create();

        $wrongPassword = $this->postJson('/api/v1/auth/login', [...$this->credentials($agent, ClientType::FieldDevice), 'password' => 'not-the-password']);
        $unknownAccount = $this->postJson('/api/v1/auth/login', [...$this->credentials($agent, ClientType::FieldDevice), 'email' => 'nobody@example.org']);

        $wrongPassword->assertUnprocessable()->assertJsonValidationErrors('email');
        $unknownAccount->assertUnprocessable()->assertJsonValidationErrors('email');
        self::assertSame($wrongPassword->json('errors'), $unknownAccount->json('errors'));
        self::assertSame(0, PersonalAccessToken::query()->count());
    }

    #[Test]
    public function roles_cannot_sign_in_from_clients_they_are_not_allowed_to_use(): void
    {
        $agent = User::factory()->fieldAgent()->create();
        $donor = User::factory()->donor()->create();

        $this->postJson('/api/v1/auth/login', $this->credentials($agent, ClientType::WebDashboard))->assertForbidden();
        $this->postJson('/api/v1/auth/login', $this->credentials($donor, ClientType::FieldDevice))->assertForbidden();
    }

    #[Test]
    public function disabled_users_and_disabled_organizations_cannot_sign_in(): void
    {
        $disabledUser = User::factory()->fieldAgent()->inactive()->create();
        $userOfDisabledOrganization = User::factory()->fieldAgent()->for(Organization::factory()->inactive())->create();

        $this->postJson('/api/v1/auth/login', $this->credentials($disabledUser, ClientType::FieldDevice))->assertForbidden();
        $this->postJson('/api/v1/auth/login', $this->credentials($userOfDisabledOrganization, ClientType::FieldDevice))->assertForbidden();
        self::assertSame(0, PersonalAccessToken::query()->count());
    }

    #[Test]
    public function signing_in_again_on_the_same_device_replaces_its_token(): void
    {
        $agent = User::factory()->fieldAgent()->create();

        $first = $this->postJson('/api/v1/auth/login', $this->credentials($agent, ClientType::FieldDevice))->json('data.access_token');
        $second = $this->postJson('/api/v1/auth/login', $this->credentials($agent, ClientType::FieldDevice))->json('data.access_token');

        self::assertSame(1, $agent->tokens()->count());

        $this->forgetAuthenticatedUser();
        $this->withToken((string) $first)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->forgetAuthenticatedUser();
        $this->withToken((string) $second)->getJson('/api/v1/auth/me')->assertOk();
    }

    #[Test]
    public function the_oldest_tokens_are_pruned_beyond_the_per_user_limit(): void
    {
        config(['rebuild.auth.max_active_tokens_per_user' => 2]);
        $coordinator = User::factory()->coordinator()->create();

        foreach (['device-aaaa-0001', 'device-aaaa-0002', 'device-aaaa-0003'] as $index => $deviceId) {
            Carbon::setTestNow(Carbon::parse('2026-10-12 08:00:00')->addMinutes($index));
            $this->issueToken($coordinator, ClientType::FieldDevice, $deviceId);
        }

        self::assertEqualsCanonicalizing(
            ['device-aaaa-0002', 'device-aaaa-0003'],
            $coordinator->tokens()->pluck('device_id')->all(),
        );
    }

    #[Test]
    public function login_attempts_are_rate_limited_per_account(): void
    {
        $agent = User::factory()->fieldAgent()->create();
        $payload = [...$this->credentials($agent, ClientType::FieldDevice), 'password' => 'wrong-password'];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', $payload)->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', $payload)->assertTooManyRequests();
    }

    /**
     * @return array<string, string>
     */
    private function credentials(User $user, ClientType $clientType): array
    {
        return [
            'email' => $user->email,
            'password' => UserFactory::DEFAULT_PASSWORD,
            'client_type' => $clientType->value,
            'device_name' => 'PHPUnit client',
            'device_id' => self::DEVICE_ID,
        ];
    }
}
