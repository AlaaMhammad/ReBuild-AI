<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Exceptions\TenantViolationException;
use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Row-level multi-tenancy for models carrying an organization_id column.
 *
 * - Reads are filtered by OrganizationScope whenever a tenant context is active.
 * - On create, organization_id is filled from the context, and an explicit
 *   organization_id that disagrees with the context is rejected.
 * - organization_id is immutable after creation.
 *
 * @mixin Model
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(static function (Model $model): void {
            $contextId = app(TenantContext::class)->id();
            $recordId = $model->getAttribute('organization_id');

            if ($recordId === null) {
                if ($contextId === null) {
                    throw TenantViolationException::missingOrganization($model::class);
                }

                $model->setAttribute('organization_id', $contextId);

                return;
            }

            if ($contextId !== null && (string) $recordId !== $contextId) {
                throw TenantViolationException::crossTenantWrite($model::class, (string) $recordId, $contextId);
            }
        });

        static::updating(static function (Model $model): void {
            if ($model->isDirty('organization_id')) {
                throw TenantViolationException::organizationReassignment($model::class);
            }
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Explicitly scope a query to one organization, independent of the tenant context.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForOrganization(Builder $query, Organization|string $organization): Builder
    {
        $organizationId = $organization instanceof Organization ? $organization->getKey() : $organization;

        return $query
            ->withoutGlobalScope(OrganizationScope::class)
            ->where($this->qualifyColumn('organization_id'), $organizationId);
    }
}
