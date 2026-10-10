<?php

use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Makes requests from the SPA (listed in SANCTUM_STATEFUL_DOMAINS) use cookies + sessions + CSRF.
        $middleware->statefulApi();

        // On a host (Render, Netlify...) every request arrives through the host's own proxy. Believe its X-Forwarded-For and
        // X-Forwarded-Proto headers, otherwise every visitor looks like the same IP address (rate limits and the audit log
        // would treat everyone as one person) and https pages think they are http. Only safe because the app is reachable
        // through the host's proxy alone.
        $middleware->trustProxies(at: '*');

        // Signs a deactivated user out on their next request, even if their session was already open.
        $middleware->api(append: [EnsureUserIsActive::class]);
        // Laravel runs authentication before middleware it does not know about. This one must run first, otherwise the
        // request of a deactivated user would already be authenticated by the time it is signed out.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: EnsureUserIsActive::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Consistent { "message": ... } bodies for common API errors. They never include
        // class names, file paths or stack traces, even when APP_DEBUG=true.
        $wantsJson = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) use ($wantsJson) {
            if ($wantsJson($request)) {
                return response()->json(['message' => 'Resource not found.'], 404);
            }
        });

        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) use ($wantsJson) {
            if ($wantsJson($request)) {
                return response()->json(['message' => 'You do not have permission to perform this action.'], 403);
            }
        });

        // Laravel turns a CSRF failure into a plain HttpException with status 419.
        $exceptions->render(function (HttpException $e, Request $request) use ($wantsJson) {
            if ($e->getStatusCode() === 419 && $wantsJson($request)) {
                return response()->json(['message' => 'Your session expired. Please refresh and try again.'], 419);
            }
        });
    })->create();
