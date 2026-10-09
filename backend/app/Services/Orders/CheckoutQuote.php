<?php

namespace App\Services\Orders;

use App\Models\Address;
use App\Models\Coupon;
use App\Services\Cart\EvaluatedCart;
use App\Support\Money;

/** What an order would cost right now. Read-only: nothing is reserved or saved. */
final readonly class CheckoutQuote
{
    /** @param  array<int, string>  $blockers  reasons the order cannot be placed yet, in plain words */
    public function __construct(
        public EvaluatedCart $cart,
        public ?Address $address,
        public ?Coupon $coupon,
        public ?string $couponError,
        public Money $subtotal,
        public Money $discount,
        public ?Money $shipping,   // unknown until a delivery address is chosen
        public Money $total,       // includes shipping once it is known
        public array $blockers,
    ) {}

    public function canPlace(): bool
    {
        return $this->blockers === [] && $this->address !== null && $this->couponError === null;
    }
}
