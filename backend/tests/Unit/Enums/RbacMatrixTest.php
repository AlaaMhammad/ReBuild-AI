<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\ClientType;
use App\Enums\TokenAbility;
use App\Enums\UserRole;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the permissions matrix documented in docs/security/authentication-rbac.md.
 * A failing assertion here means the documentation must be updated too.
 */
final class RbacMatrixTest extends TestCase
{
    #[Test]
    public function every_role_can_read_its_own_profile(): void
    {
        foreach (UserRole::cases() as $role) {
            self::assertContains(TokenAbility::ProfileRead, $role->abilities(), $role->value);
        }
    }

    #[Test]
    public function only_admins_manage_users_and_the_organization(): void
    {
        foreach (UserRole::cases() as $role) {
            $expected = $role === UserRole::Admin;

            self::assertSame($expected, in_array(TokenAbility::UsersManage, $role->abilities(), true), $role->value);
            self::assertSame($expected, in_array(TokenAbility::OrganizationManage, $role->abilities(), true), $role->value);
        }
    }

    #[Test]
    public function decrypted_national_ids_are_limited_to_admins_and_coordinators(): void
    {
        $holders = array_values(array_filter(
            UserRole::cases(),
            static fn (UserRole $role): bool => in_array(TokenAbility::BeneficiariesPii, $role->abilities(), true),
        ));

        self::assertEqualsCanonicalizing([UserRole::Admin, UserRole::Coordinator], $holders);
    }

    #[Test]
    public function donors_receive_no_beneficiary_or_write_access(): void
    {
        self::assertSame(
            [TokenAbility::ProfileRead, TokenAbility::ReconstructionRead, TokenAbility::ReportsRead],
            UserRole::Donor->abilities(),
        );
    }

    #[Test]
    public function field_device_tokens_never_carry_administrative_or_review_abilities(): void
    {
        $forbidden = [
            TokenAbility::UsersManage,
            TokenAbility::OrganizationManage,
            TokenAbility::AssessmentsReview,
            TokenAbility::BeneficiariesPii,
            TokenAbility::ReliefManage,
            TokenAbility::ReconstructionManage,
            TokenAbility::AuditRead,
        ];

        foreach (UserRole::cases() as $role) {
            foreach ($forbidden as $ability) {
                self::assertNotContains($ability, $role->abilitiesFor(ClientType::FieldDevice), "{$role->value} / {$ability->value}");
            }
        }
    }

    #[Test]
    public function the_coordinator_field_token_is_a_strict_subset_of_the_web_token(): void
    {
        $field = UserRole::Coordinator->abilitiesFor(ClientType::FieldDevice);
        $web = UserRole::Coordinator->abilitiesFor(ClientType::WebDashboard);

        self::assertSame($web, UserRole::Coordinator->abilities());
        self::assertEmpty(array_udiff($field, $web, static fn (TokenAbility $a, TokenAbility $b): int => strcmp($a->value, $b->value)));
        self::assertLessThan(count($web), count($field));
    }

    #[Test]
    public function client_type_permissions_per_role(): void
    {
        self::assertSame([ClientType::WebDashboard], UserRole::Admin->allowedClientTypes());
        self::assertSame([ClientType::FieldDevice, ClientType::WebDashboard], UserRole::Coordinator->allowedClientTypes());
        self::assertSame([ClientType::FieldDevice], UserRole::FieldAgent->allowedClientTypes());
        self::assertSame([ClientType::FieldDevice, ClientType::WebDashboard], UserRole::Contractor->allowedClientTypes());
        self::assertSame([ClientType::WebDashboard], UserRole::Donor->allowedClientTypes());
    }

    #[Test]
    public function ability_values_are_unique_and_namespaced(): void
    {
        $values = TokenAbility::values();

        self::assertSame($values, array_values(array_unique($values)));

        foreach ($values as $value) {
            self::assertMatchesRegularExpression('/^[a-z]+:[a-z]+$/', $value);
        }
    }
}
