<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrganizationType;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
final class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company().' Relief';

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'type' => OrganizationType::Ngo,
            'registration_number' => fake()->optional()->bothify('REG-####-????'),
            'contact_email' => fake()->companyEmail(),
            'contact_phone' => fake()->optional()->e164PhoneNumber(),
            'settings' => [],
            'is_active' => true,
        ];
    }

    public function ofType(OrganizationType $type): self
    {
        return $this->state(['type' => $type]);
    }

    public function inactive(): self
    {
        return $this->state(['is_active' => false]);
    }
}
