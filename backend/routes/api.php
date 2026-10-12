<?php

declare(strict_types=1);

use App\Enums\TokenAbility;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\TokenController;
use App\Http\Controllers\Api\V1\UserTokenController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Every authenticated route runs through auth:sanctum and then "tenant",
| which binds the caller's organization for row-level isolation. Ability
| checks use Sanctum's "ability"/"abilities" middleware against the
| TokenAbility values embedded in the bearer token.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:auth-login')
        ->name('auth.login');

    Route::middleware(['auth:sanctum', 'tenant', 'throttle:api'])->group(function (): void {
        Route::prefix('auth')->name('auth.')->group(function (): void {
            Route::get('me', [AuthController::class, 'me'])
                ->middleware('ability:'.TokenAbility::ProfileRead->value)
                ->name('me');
            Route::post('refresh', [AuthController::class, 'refresh'])->name('refresh');
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::post('logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');

            Route::get('tokens', [TokenController::class, 'index'])->name('tokens.index');
            Route::delete('tokens/{tokenId}', [TokenController::class, 'destroy'])
                ->whereNumber('tokenId')
                ->name('tokens.destroy');
        });

        Route::delete('users/{user}/tokens', [UserTokenController::class, 'destroy'])
            ->middleware('abilities:'.TokenAbility::UsersManage->value)
            ->whereUuid('user')
            ->name('users.tokens.destroy');
    });
});
