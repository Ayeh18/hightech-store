<?php

namespace App\Services\Orders;

use App\Models\Coupon;
use App\Support\Money;

/** A coupon that passed every check, with the discount it gives on this order. */
final readonly class CouponApplication
{
    public function __construct(public Coupon $coupon, public Money $discount) {}
}
