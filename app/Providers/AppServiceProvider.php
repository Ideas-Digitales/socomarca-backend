<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Data\UserService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use App\Services\RandomApiService;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(RandomApiService::class, function ($app) {
            return new RandomApiService();
        });
        $this->app->singleton(UserService::class, function ($app) {
            return new UserService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Tokens issued before a user was deactivated must stop working on every route.
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $accessToken, bool $isValid) => $isValid
                && (bool) $accessToken->tokenable?->is_active
        );

        Mail::extend('brevo', function () {
            return (new BrevoTransportFactory)->create(
                new Dsn(
                    'brevo+api',
                    'default',
                    config('services.brevo.key')
                )
            );
        });

        Str::macro('maskEmail', function (string $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }

            [$parts, $domain] = explode('@', $email);

            $maskedPart = Str::mask($parts, '*', 1, max(1, strlen($parts) - 2));

            return $maskedPart . '@' . $domain;
        });
    }
}
