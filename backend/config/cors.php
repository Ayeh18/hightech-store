<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Which other websites may call the API from a visitor's browser
    |--------------------------------------------------------------------------
    | Only the shop's own website. Normally the browser does not even need this: the website forwards /api to the API, so
    | both share one address (see netlify.toml and the Vite dev server). It matters when the two are on different addresses,
    | and it stops any other site's JavaScript from reading API answers. Laravel's default is "*": every website.
    |
    | Payment webhooks are not affected: they come from a server, not from a browser.
    */
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // The login is a cookie, so the shop's website must be allowed to send it.
    'supports_credentials' => true,
];
