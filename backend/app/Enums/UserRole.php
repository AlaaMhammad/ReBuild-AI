<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Organization-scoped roles. Every role operates only inside its own
 * organization; cross-organization processing (deduplication) runs as a
 * system process, never as a user.
 */
enum UserRole: string
{
    use HasValues;

    case Admin = 'admin';
    case Coordinator = 'coordinator';
    case FieldAgent = 'field_agent';
    case Contractor = 'contractor';
    case Donor = 'donor';

    /**
     * Full ability set granted to the role (before the client-type ceiling is applied).
     *
     * @return list<TokenAbility>
     */
    public function abilities(): array
    {
        return match ($this) {
            self::Admin => TokenAbility::cases(),
            self::Coordinator => [
                TokenAbility::ProfileRead,
                TokenAbility::SyncPush,
                TokenAbility::SyncPull,
                TokenAbility::BeneficiariesRead,
                TokenAbility::BeneficiariesWrite,
                TokenAbility::BeneficiariesPii,
                TokenAbility::AssessmentsRead,
                TokenAbility::AssessmentsWrite,
                TokenAbility::AssessmentsReview,
                TokenAbility::ImagesUpload,
                TokenAbility::ReliefRead,
                TokenAbility::ReliefManage,
                TokenAbility::ReconstructionRead,
                TokenAbility::ReconstructionManage,
                TokenAbility::ReportsRead,
                TokenAbility::AuditRead,
            ],
            self::FieldAgent => [
                TokenAbility::ProfileRead,
                TokenAbility::SyncPush,
                TokenAbility::SyncPull,
                TokenAbility::BeneficiariesRead,
                TokenAbility::BeneficiariesWrite,
                TokenAbility::AssessmentsRead,
                TokenAbility::AssessmentsWrite,
                TokenAbility::ImagesUpload,
            ],
            self::Contractor => [
                TokenAbility::ProfileRead,
                TokenAbility::SyncPush,
                TokenAbility::SyncPull,
                TokenAbility::AssessmentsRead,
                TokenAbility::ImagesUpload,
                TokenAbility::ReconstructionRead,
                TokenAbility::ReconstructionUpdate,
            ],
            self::Donor => [
                TokenAbility::ProfileRead,
                TokenAbility::ReconstructionRead,
                TokenAbility::ReportsRead,
            ],
        };
    }

    /**
     * @return list<ClientType>
     */
    public function allowedClientTypes(): array
    {
        return match ($this) {
            self::Admin, self::Donor => [ClientType::WebDashboard],
            self::Coordinator, self::Contractor => [ClientType::FieldDevice, ClientType::WebDashboard],
            self::FieldAgent => [ClientType::FieldDevice],
        };
    }

    public function canUseClient(ClientType $clientType): bool
    {
        return in_array($clientType, $this->allowedClientTypes(), true);
    }

    /**
     * Abilities to embed in a token: the role's abilities intersected with the
     * client type's ceiling.
     *
     * @return list<TokenAbility>
     */
    public function abilitiesFor(ClientType $clientType): array
    {
        $ceiling = $clientType->abilityCeiling();

        return array_values(array_filter(
            $this->abilities(),
            static fn (TokenAbility $ability): bool => in_array($ability, $ceiling, true),
        ));
    }
}
