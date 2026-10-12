<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts tenant-owned models to the organization in the active TenantContext.
 */
final class OrganizationScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $organizationId = app(TenantContext::class)->id();

        if ($organizationId !== null) {
            $builder->where($model->qualifyColumn('organization_id'), $organizationId);
        }
    }
}
