<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Permission-based authorization: before any Gate/Policy check runs, grant the ability
        // if one of the user's roles has a permission with that exact key (e.g. "products.manage").
        // Returning null (not false) means "no opinion", so normal Policies still get a say.
        // Adding a new permission therefore needs a database row, not a code change.
        Gate::before(fn (User $user, string $ability) => $user->hasPermission($ability) ? true : null);
    }
}
