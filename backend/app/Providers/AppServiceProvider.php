<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\Identity\NationalIdHasher;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: flushed between requests (Octane) and between queued jobs.
        $this->app->scoped(TenantContext::class);

        $this->app->singleton(
            NationalIdHasher::class,
            static fn (): NationalIdHasher => NationalIdHasher::fromConfig(config('rebuild.pii.national_id_hash_key')),
        );
    }

    public function boot(): void
    {
        Date::use(CarbonImmutable::class);

        Model::shouldBeStrict(! $this->app->isProduction());

        $this->configureSanctum();
        $this->configureRateLimiting();
    }

    private function configureSanctum(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Evaluated on every bearer-authenticated request: a disabled user, a
        // disabled or deleted organization, or a token whose client type the
        // role may no longer use is rejected even before the token expires.
        Sanctum::authenticateAccessTokensUsing(
            static function (PersonalAccessToken $accessToken, bool $isValid): bool {
                if (! $isValid) {
                    return false;
                }

                $user = $accessToken->tokenable;

                return $user instanceof User && $user->canAuthenticateVia($accessToken->client_type);
            },
        );
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('auth-login', static function (Request $request): array {
            $email = mb_strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute((int) config('rebuild.auth.login_rate_limit.per_account_per_minute'))
                    ->by('login-account:'.sha1($email.'|'.$request->ip())),
                Limit::perMinute((int) config('rebuild.auth.login_rate_limit.per_ip_per_minute'))
                    ->by('login-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('api', static function (Request $request): Limit {
            $perMinute = (int) config('rebuild.auth.api_rate_limit_per_minute');
            $user = $request->user();

            return $user instanceof User
                ? Limit::perMinute($perMinute)->by('api-user:'.$user->getAuthIdentifier())
                : Limit::perMinute($perMinute)->by('api-ip:'.$request->ip());
        });
    }
}
