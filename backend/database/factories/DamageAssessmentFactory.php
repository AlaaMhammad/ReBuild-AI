<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AssessmentStatus;
use App\Enums\DamageTier;
use App\Enums\HabitabilityStatus;
use App\Enums\NeedCategory;
use App\Enums\StructureType;
use App\Models\Beneficiary;
use App\Models\DamageAssessment;
use App\Models\Organization;
use App\Models\User;
use App\Support\Geo\GeoPoint;
use Database\Factories\Concerns\GeneratesGazaLocations;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DamageAssessment>
 */
final class DamageAssessmentFactory extends Factory
{
    use GeneratesGazaLocations;

    protected $model = DamageAssessment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        [$location, $governorate] = $this->gazaLocation();
        $tier = fake()->randomElement(DamageTier::cases());
        $assessedAt = now()->subHours(fake()->numberBetween(1, 24 * 30));

        return [
            'organization_id' => Organization::factory(),
            'client_uuid' => (string) Str::uuid(),
            'location' => $location,
            'location_accuracy_m' => fake()->randomFloat(2, 2, 35),
            'governorate' => $governorate,
            'address_description' => fake()->optional()->streetAddress(),
            'structure_type' => fake()->randomElement(StructureType::cases()),
            'damage_tier' => $tier,
            'habitability' => match ($tier) {
                DamageTier::None, DamageTier::Minor => HabitabilityStatus::Habitable,
                DamageTier::Moderate => HabitabilityStatus::PartiallyHabitable,
                DamageTier::Severe, DamageTier::Destroyed => HabitabilityStatus::Uninhabitable,
            },
            'floors_count' => fake()->numberBetween(1, 8),
            'units_count' => fake()->numberBetween(1, 16),
            'urgent_needs' => array_map(
                static fn (NeedCategory $category): array => [
                    'category' => $category->value,
                    'quantity' => fake()->numberBetween(1, 10),
                ],
                fake()->randomElements(NeedCategory::cases(), fake()->numberBetween(0, 3)),
            ),
            'status' => AssessmentStatus::Submitted,
            'assessed_at' => $assessedAt,
            'client_created_at' => $assessedAt,
            'client_updated_at' => $assessedAt,
        ];
    }

    public function at(GeoPoint $location): self
    {
        return $this->state(['location' => $location]);
    }

    public function tier(DamageTier $tier): self
    {
        return $this->state(['damage_tier' => $tier]);
    }

    public function assessedBy(User $assessor): self
    {
        return $this->state([
            'organization_id' => $assessor->organization_id,
            'assessor_id' => $assessor->id,
        ]);
    }

    public function forBeneficiary(Beneficiary $beneficiary): self
    {
        return $this->state([
            'organization_id' => $beneficiary->organization_id,
            'beneficiary_id' => $beneficiary->id,
        ]);
    }
}
