<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Data\UserService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use App\Services\RandomApiService;
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
        Mail::extend('brevo', function () {
            return (new BrevoTransportFactory)->create(
                new Dsn(
                    'brevo+api',
                    'default',
                    config('services.brevo.key')
                )
            );
        });

        Gate::define('view-credit-line', function (User $authUser, User $targetUser) {
            if ($authUser->id === $targetUser->id) {
                return $authUser->can('read-own-credit-line');
            }

            return $authUser->can('read-all-credit-line');
        });
    }
}
