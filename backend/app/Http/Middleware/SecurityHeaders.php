<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds the headers that tell a browser how an answer may be used. They cost nothing and close off whole kinds of attack:
 *
 *  - nosniff: use the stated content type, never guess (a file dressed up as an image cannot be run as a script);
 *  - no framing: the answer cannot be shown inside another site's page (clickjacking);
 *  - referrer policy: other sites are told which site a visitor came from, not which page;
 *  - permissions policy: no page from here may use the camera, microphone, location or payment sheet;
 *  - API answers are data, so nothing in them may load or run anything at all;
 *  - once seen over https, always https (HSTS).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), geolocation=(), microphone=(), payment=()');

        if ($request->is('api/*', 'sanctum/*')) {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }

        // Only over https: sent over plain http it would be ignored, and it must never lock a developer's localhost into https.
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // PHP announces its exact version unless told not to (the production image also sets expose_php = Off).
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        return $response;
    }
}
