<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DisplacementStatus;
use App\Enums\Gender;
use App\Enums\Governorate;
use App\Enums\VulnerabilityTag;
use App\Models\Beneficiary;
use App\Models\Organization;
use App\Models\User;
use App\Support\Identity\NationalId;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Beneficiary>
 */
final class BeneficiaryFactory extends Factory
{
    protected $model = Beneficiary::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $householdSize = fake()->numberBetween(1, 14);
        $children = fake()->numberBetween(0, max(0, $householdSize - 1));
        $elderly = fake()->numberBetween(0, $householdSize - $children);
        $gender = fake()->randomElement(Gender::cases());

        return [
            'organization_id' => Organization::factory(),
            'client_uuid' => (string) Str::uuid(),
            'national_id' => self::fakeNationalId(),
            'full_name' => fake('ar_SA')->name($gender === Gender::Male ? 'male' : 'female'),
            'gender' => $gender,
            'date_of_birth' => fake()->dateTimeBetween('-80 years', '-18 years')->format('Y-m-d'),
            'phone' => fake()->optional()->numerify('+9705########'),
            'governorate' => fake()->randomElement(Governorate::cases()),
            'displacement_status' => fake()->randomElement(DisplacementStatus::cases()),
            'address_description' => fake()->optional()->streetAddress(),
            'household_size' => $householdSize,
            'children_count' => $children,
            'elderly_count' => $elderly,
            'disabled_count' => fake()->numberBetween(0, min(2, $householdSize)),
            'pregnant_lactating_count' => fake()->numberBetween(0, min(1, $householdSize)),
            'is_female_headed' => $gender === Gender::Female,
            'vulnerability_tags' => fake()->randomElements(VulnerabilityTag::cases(), fake()->numberBetween(0, 3)),
            'client_created_at' => now()->subDays(fake()->numberBetween(0, 30)),
        ];
    }

    /**
     * A random, checksum-valid 9-digit Palestinian ID.
     */
    public static function fakeNationalId(): string
    {
        return NationalId::withCheckDigit(fake()->randomElement(['4', '8', '9']).fake()->numerify('#######'));
    }

    public function registeredBy(User $user): self
    {
        return $this->state([
            'organization_id' => $user->organization_id,
            'registered_by' => $user->id,
        ]);
    }

    public function withoutNationalId(): self
    {
        return $this->state(['national_id' => null]);
    }
}
