<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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

        // Tells browsers what every answer may and may not be used for (never framed, never guessed at, https only).
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Every error the API answers with is { "message": ... } (plus "errors" for a form that failed validation). An
        // expected error never includes class names, file paths or stack traces, even when APP_DEBUG=true.
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

        // Everything else that carries an HTTP status: an expired session (419, Laravel's CSRF failure), the wrong method
        // (405), too many attempts (429), and the errors the shop raises itself with abort(). Laravel's own answer for
        // these adds the exception class and a stack trace whenever APP_DEBUG is on.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            $status = $e->getStatusCode();
            $message = match (true) {
                $status === 404 => 'Resource not found.',
                $status === 419 => 'Your session expired. Please refresh and try again.',
                $status === 405 => 'That action is not available at this address.',
                $status === 429 => 'Too many attempts. Please wait a moment and try again.',
                $status >= 500 && $status !== 503 => 'Something went wrong on our side. Please try again shortly.',
                default => $e->getMessage() ?: 'The request could not be completed.',
            };

            return response()->json(['message' => $message], $status, $e->getHeaders()); // headers: e.g. Retry-After
        });

        // A bug or an outage: something nobody planned for. The details go to the log, never to the visitor. While
        // developing (APP_DEBUG=true) Laravel's detailed page is kept, because that is what it is for.
        $exceptions->render(function (Throwable $e, Request $request) use ($wantsJson) {
            $handledByLaravel = $e instanceof ValidationException || $e instanceof AuthenticationException || $e instanceof HttpResponseException;

            if ($wantsJson($request) && ! $handledByLaravel && ! config('app.debug')) {
                return response()->json(['message' => 'Something went wrong on our side. Please try again shortly.'], 500);
            }
        });
    })->create();
