<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Shipping (amounts in sen: 1500 = RM 15.00)
    |--------------------------------------------------------------------------
    | A flat rate per zone, free above a threshold. The threshold is judged on the order value AFTER discounts.
    */
    'shipping' => [
        'west_malaysia' => 1500,
        'east_malaysia' => 3500,   // Sabah, Sarawak and Labuan
        'free_over' => 300000,     // RM 3,000.00
    ],

    // How long an unpaid order holds its stock before it is cancelled and the stock is released.
    'payment_window_minutes' => 30,

    // The shop's own clock. Timestamps are stored in UTC; reports group them into days as the shop (and its customers) see them.
    'timezone' => env('SHOP_TIMEZONE', 'Asia/Kuala_Lumpur'),

    'max_addresses' => 10,

    /*
    |--------------------------------------------------------------------------
    | First-run setup (php artisan shop:install)
    |--------------------------------------------------------------------------
    | Used when the shop is put on a real server for the first time. It creates the first admin from these settings
    | (never a demo account with a known password) and can fill an EMPTY catalog with the demo products.
    */
    'install' => [
        'admin_email' => env('ADMIN_EMAIL'),
        'admin_password' => env('ADMIN_PASSWORD'),
        'admin_name' => env('ADMIN_NAME', 'Shop Admin'),
        'demo_catalog' => (bool) env('SEED_DEMO_CATALOG', false),
    ],

    // A customer can ask for a refund until this many days after their order was delivered.
    'refund_window_days' => 14,

    // The address of the Vue app. Used in emails and in the address a customer is sent back to after paying.
    'frontend_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/'),
];
