<?php

namespace App\Services\Cart;

use App\Enums\BuilderSlot;
use App\Models\Product;
use App\Support\Money;

/** One product inside a cart line: the single product of a product line, or one component of a build line. */
final readonly class CartLinePart
{
    public function __construct(
        public ?BuilderSlot $slot,
        public Product $product,
        public int $quantity,          // per unit of the line (a build component may be 2 RAM kits)
        public bool $onSale,
        public ?Money $unitPrice,      // current price; null when the product is no longer on sale
        public ?Money $priceWhenAdded, // build components only
    ) {}
}
