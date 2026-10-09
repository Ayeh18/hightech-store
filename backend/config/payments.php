<?php

use App\Services\Payments\Gateways\FakeGateway;

return [
    /*
    |--------------------------------------------------------------------------
    | Payment gateway
    |--------------------------------------------------------------------------
    | Which gateway new payments use. The rest of the shop only talks to the PaymentGateway interface, so adding a
    | real Malaysian gateway means writing one class and listing it below; nothing else changes.
    */
    'default' => env('PAYMENT_PROVIDER', 'fake'),

    // name => class. "fake" is a local simulator and is refused when APP_ENV=production.
    'gateways' => [
        'fake' => FakeGateway::class,
    ],

    // Gateways that exist only for local development and testing. They are refused when APP_ENV=production.
    'local_only' => ['fake'],

    // A webhook whose timestamp is older than this is rejected, so a captured request cannot be replayed later.
    'webhook_tolerance_seconds' => 300,

    // A payment page that was requested but never answered (the app crashed in between) is treated as dead after this.
    'setup_grace_seconds' => 120,

    // Payments still undecided after this long are asked about at the gateway (php artisan payments:reconcile).
    'reconcile_after_minutes' => 3,

    'fake' => [
        'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET'),
        // Where the simulator delivers its webhooks. From the queue container the app is reachable as http://app:8000.
        'webhook_url' => env('PAYMENT_FAKE_WEBHOOK_URL', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/webhooks/fake'),
    ],
];
