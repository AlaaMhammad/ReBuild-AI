<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\OrganizationType;
use App\Enums\UserRole;
use App\Models\Beneficiary;
use App\Models\DamageAssessment;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Local/staging demo data: one organization, one account per role, and a
 * spread of beneficiaries and assessments across the five governorates.
 * Passwords are generated per run and printed once.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The demo seeder must never run in production.');
        }

        $organization = Organization::factory()
            ->ofType(OrganizationType::Ngo)
            ->create(['name' => 'Demo Relief Network', 'slug' => 'demo-relief-network']);

        $credentials = [];
        $users = [];

        foreach (UserRole::cases() as $role) {
            $password = Str::password(16);
            $email = str_replace('_', '.', $role->value).'@demo.rebuild.test';

            $users[$role->value] = User::factory()
                ->for($organization)
                ->role($role)
                ->create(['email' => $email, 'name' => 'Demo '.Str::headline($role->value), 'password' => $password]);

            $credentials[] = [$role->value, $email, $password];
        }

        $fieldAgent = $users[UserRole::FieldAgent->value];

        $beneficiaries = Beneficiary::factory()
            ->count(60)
            ->registeredBy($fieldAgent)
            ->create();

        foreach ($beneficiaries as $beneficiary) {
            DamageAssessment::factory()
                ->assessedBy($fieldAgent)
                ->forBeneficiary($beneficiary)
                ->create();
        }

        DamageAssessment::factory()
            ->count(140)
            ->assessedBy($fieldAgent)
            ->create();

        $this->command->table(['Role', 'Email', 'Password'], $credentials);
        $this->command->warn('Demo passwords are shown once and are not stored anywhere else.');
    }
}
