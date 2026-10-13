<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * The Horizon dashboard is session-authenticated (web guard), which the
     * API does not use. Outside the local environment it is therefore closed
     * to everyone unless operators are listed in HORIZON_ALLOWED_EMAILS and
     * the dashboard is exposed behind the operator VPN / reverse proxy.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', static function ($user = null): bool {
            $allowed = array_filter(array_map(
                static fn (string $email): string => mb_strtolower(trim($email)),
                explode(',', (string) config('rebuild.horizon.allowed_emails', '')),
            ));

            return $user !== null && in_array(mb_strtolower((string) $user->email), $allowed, true);
        });
    }
}
