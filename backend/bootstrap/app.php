<?php

declare(strict_types=1);

use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [ForceJsonResponse::class]);

        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'tenant' => SetTenantContext::class,
        ]);

        // The tenant must be bound before route-model binding resolves
        // {models}, so that bindings are filtered by organization.
        $middleware->appendToPriorityList(AuthenticatesRequests::class, SetTenantContext::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->dontFlash(['password', 'password_confirmation', 'national_id']);
    })->create();
