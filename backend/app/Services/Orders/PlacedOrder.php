<?php

namespace App\Services\Orders;

use App\Models\Order;

/** The order a checkout produced. `created` is false when the same request was already processed (a retry). */
final readonly class PlacedOrder
{
    public function __construct(public Order $order, public bool $created) {}
}
