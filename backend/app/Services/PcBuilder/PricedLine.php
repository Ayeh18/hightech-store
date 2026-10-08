<?php

namespace App\Services\PcBuilder;

use App\Enums\BuilderSlot;
use App\Models\Product;
use App\Support\Money;

/** One slot of a priced build. `product` is null when the product must not be revealed to this viewer. */
final readonly class PricedLine
{
    public function __construct(
        public BuilderSlot $slot,
        public int $quantity,
        public ?Product $product,
        public ?Money $unitPrice,
        public ?Money $lineTotal,
        public bool $purchasable,
    ) {}
}
