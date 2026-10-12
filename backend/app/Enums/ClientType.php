<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * The kind of client a token is issued to. Field devices hold long-lived tokens
 * (they may be offline for weeks) but are capped to field abilities, because a
 * phone in a conflict zone is far more likely to be lost or seized than an
 * office browser session.
 */
enum ClientType: string
{
    use HasValues;

    case FieldDevice = 'field_device';
    case WebDashboard = 'web_dashboard';

    public function tokenTtlMinutes(): int
    {
        return (int) config("rebuild.auth.token_ttl_minutes.{$this->value}");
    }

    /**
     * Upper bound of abilities a token of this client type may carry, regardless of role.
     *
     * @return list<TokenAbility>
     */
    public function abilityCeiling(): array
    {
        return match ($this) {
            self::FieldDevice => [
                TokenAbility::ProfileRead,
                TokenAbility::SyncPush,
                TokenAbility::SyncPull,
                TokenAbility::BeneficiariesRead,
                TokenAbility::BeneficiariesWrite,
                TokenAbility::AssessmentsRead,
                TokenAbility::AssessmentsWrite,
                TokenAbility::ImagesUpload,
                TokenAbility::ReconstructionRead,
                TokenAbility::ReconstructionUpdate,
            ],
            self::WebDashboard => TokenAbility::cases(),
        };
    }

    public function requiresDeviceId(): bool
    {
        return $this === self::FieldDevice;
    }
}
