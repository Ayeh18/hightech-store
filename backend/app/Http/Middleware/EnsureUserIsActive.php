<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A deactivated user is signed out on their very next request. Logging in already refuses them, but without this a
 * session that was open at the moment they were deactivated would keep working until it expired.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        // Only an explicit `false` counts: a user object that has not loaded the column (just created) is not a deactivated one.
        if ($user !== null && $user->is_active === false) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
            }
        }

        return $next($request);
    }
}
