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

    'max_addresses' => 10,

    // A customer can ask for a refund until this many days after their order was delivered.
    'refund_window_days' => 14,

    // The address of the Vue app. Used in emails and in the address a customer is sent back to after paying.
    'frontend_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/'),
];
