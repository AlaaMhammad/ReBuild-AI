<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Organization;
use Closure;

/**
 * Holds the organization the current unit of work acts for.
 *
 * Registered as a scoped binding: it is reset between HTTP requests under
 * Octane and between queued jobs, so tenant state can never leak from one
 * unit of work to the next. When no tenant is set (console, system jobs),
 * tenant-owned models are not filtered and callers are responsible for
 * scoping explicitly.
 */
final class TenantContext
{
    private ?string $organizationId = null;

    public function set(Organization|string $organization): void
    {
        $this->organizationId = $organization instanceof Organization
            ? (string) $organization->getKey()
            : $organization;
    }

    public function clear(): void
    {
        $this->organizationId = null;
    }

    public function id(): ?string
    {
        return $this->organizationId;
    }

    public function isSet(): bool
    {
        return $this->organizationId !== null;
    }

    /**
     * Runs the callback as the given organization and restores the previous context afterwards.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runAs(Organization|string $organization, Closure $callback): mixed
    {
        $previous = $this->organizationId;
        $this->set($organization);

        try {
            return $callback();
        } finally {
            $this->organizationId = $previous;
        }
    }

    /**
     * Runs the callback with tenant filtering disabled (system-level processing).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runWithoutTenant(Closure $callback): mixed
    {
        $previous = $this->organizationId;
        $this->organizationId = null;

        try {
            return $callback();
        } finally {
            $this->organizationId = $previous;
        }
    }
}
