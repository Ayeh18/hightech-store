<?php

use Illuminate\Auth\Access\AuthorizationException;
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
