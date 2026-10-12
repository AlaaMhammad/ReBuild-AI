<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Sanctum token abilities. Abilities are frozen into a token at issuance, so
 * any change to a user's role revokes their existing tokens (see User::booted).
 */
enum TokenAbility: string
{
    use HasValues;

    case ProfileRead = 'profile:read';

    case SyncPush = 'sync:push';
    case SyncPull = 'sync:pull';

    case BeneficiariesRead = 'beneficiaries:read';
    case BeneficiariesWrite = 'beneficiaries:write';
    case BeneficiariesPii = 'beneficiaries:pii';

    case AssessmentsRead = 'assessments:read';
    case AssessmentsWrite = 'assessments:write';
    case AssessmentsReview = 'assessments:review';
    case ImagesUpload = 'images:upload';

    case ReliefRead = 'relief:read';
    case ReliefManage = 'relief:manage';

    case ReconstructionRead = 'reconstruction:read';
    case ReconstructionUpdate = 'reconstruction:update';
    case ReconstructionManage = 'reconstruction:manage';

    case ReportsRead = 'reports:read';
    case AuditRead = 'audit:read';

    case UsersManage = 'users:manage';
    case OrganizationManage = 'organization:manage';
}
