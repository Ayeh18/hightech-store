<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Compatibility\RuleRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The compatibility rules listed in config/compatibility.php, built once per request.
        $this->app->singleton(RuleRegistry::class, fn ($app) => new RuleRegistry(
            array_map(fn (string $class) => $app->make($class), config('compatibility.rules')),
        ));
    }

    public function boot(): void
    {
        // N+1 guard: loading a relation lazily inside a loop throws in development and tests,
        // so missing eager loading is caught immediately instead of silently slowing the API.
        Model::preventLazyLoading(! $this->app->isProduction());

        // Permission-based authorization: before any Gate/Policy check runs, grant the ability
        // if one of the user's roles has a permission with that exact key (e.g. "products.manage").
        // Returning null (not false) means "no opinion", so normal Policies still get a say.
        // Adding a new permission therefore needs a database row, not a code change.
        Gate::before(fn (User $user, string $ability) => $user->hasPermission($ability) ? true : null);
    }
}
