<?php

namespace App\Providers;

use App\Auth\JoomlaJwtGuard;
use App\Services\Joomla\JoomlaTokenDecoder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureJoomlaGuard();
    }

    /**
     * Register the stateless guard used by external API clients.
     */
    protected function configureJoomlaGuard(): void
    {
        Auth::extend('joomla-jwt', fn ($app) => new JoomlaJwtGuard(
            $app['request'],
            $app->make(JoomlaTokenDecoder::class),
        ));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
