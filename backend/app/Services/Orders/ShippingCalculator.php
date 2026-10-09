<?php

namespace App\Services\Orders;

use App\Enums\MalaysianState;
use App\Support\Money;

/** Shipping cost: a flat rate per zone (West / East Malaysia), free above a threshold. Rates live in config/shop.php. */
class ShippingCalculator
{
    /** @param  Money  $orderValue  the order value AFTER discounts */
    public function cost(MalaysianState|string $state, Money $orderValue): Money
    {
        $state = $state instanceof MalaysianState ? $state : MalaysianState::tryFrom($state);

        if ($orderValue->sen >= (int) config('shop.shipping.free_over')) {
            return Money::zero();
        }

        $rate = $state?->isEastMalaysia()
            ? config('shop.shipping.east_malaysia')
            : config('shop.shipping.west_malaysia');

        return Money::fromSen((int) $rate);
    }
}
