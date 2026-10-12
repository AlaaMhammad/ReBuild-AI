<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * Hash computed once per process; bcrypt is deliberately slow.
     */
    private static ?string $passwordHash = null;

    public const DEFAULT_PASSWORD = 'Field-Ready-Passw0rd';

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->optional()->e164PhoneNumber(),
            'role' => UserRole::FieldAgent,
            'locale' => 'ar',
            'is_active' => true,
            'email_verified_at' => now(),
            'password' => self::$passwordHash ??= Hash::make(self::DEFAULT_PASSWORD),
            'remember_token' => Str::random(10),
        ];
    }

    public function role(UserRole $role): self
    {
        return $this->state(['role' => $role]);
    }

    public function admin(): self
    {
        return $this->role(UserRole::Admin);
    }

    public function coordinator(): self
    {
        return $this->role(UserRole::Coordinator);
    }

    public function fieldAgent(): self
    {
        return $this->role(UserRole::FieldAgent);
    }

    public function contractor(): self
    {
        return $this->role(UserRole::Contractor);
    }

    public function donor(): self
    {
        return $this->role(UserRole::Donor);
    }

    public function inactive(): self
    {
        return $this->state(['is_active' => false]);
    }

    public function unverified(): self
    {
        return $this->state(['email_verified_at' => null]);
    }
}
