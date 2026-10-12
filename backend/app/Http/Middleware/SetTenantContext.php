<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the authenticated user's organization as the tenant for the request.
 * Must run after auth:sanctum.
 */
final class SetTenantContext
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $this->tenant->set($user->organization_id);

        try {
            return $next($request);
        } finally {
            $this->tenant->clear();
        }
    }
}
