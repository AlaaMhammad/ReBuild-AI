<?php

declare(strict_types=1);

namespace App\Exceptions;

use LogicException;

/**
 * Raised when code attempts to write a tenant-owned record on behalf of a
 * different organization than the active tenant context. This always
 * indicates a programming error and must never be caught to "recover".
 */
final class TenantViolationException extends LogicException
{
    public static function crossTenantWrite(string $model, ?string $recordOrganizationId, string $contextOrganizationId): self
    {
        return new self(sprintf(
            'Refusing to persist %s for organization [%s] while acting for organization [%s].',
            $model,
            $recordOrganizationId ?? 'null',
            $contextOrganizationId,
        ));
    }

    public static function organizationReassignment(string $model): self
    {
        return new self("Refusing to move {$model} to a different organization.");
    }

    public static function missingOrganization(string $model): self
    {
        return new self("Cannot persist {$model} without an organization_id and no active tenant context.");
    }
}
