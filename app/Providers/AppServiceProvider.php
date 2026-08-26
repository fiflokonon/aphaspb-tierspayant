<?php

namespace App\Providers;

use App\Auth\JoomlaJwtGuard;
use App\Models\User;
use App\Services\Joomla\JoomlaTokenDecoder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
        $this->configureJoomlaGates();
    }

    /**
     * Map Joomla groups onto named abilities.
     *
     * Group ids live in config/joomla.php and nowhere else — a controller or a
     * view must never test one directly.
     */
    protected function configureJoomlaGates(): void
    {
        Gate::define('manage-network', fn (User $user): bool => $user->hasAnyJoomlaGroup(
            config('joomla.groups.admin'),
        ));

        Gate::define('manage-insurers', fn (User $user): bool => $user->hasAnyJoomlaGroup(
            config('joomla.groups.admin'),
        ));

        Gate::define('declare-payments', fn (User $user): bool => $user->hasAnyJoomlaGroup(
            config('joomla.groups.pharmacy'),
        ));
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
