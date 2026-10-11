<?php

namespace App\Services\Recommendations;

use App\Models\Product;
use App\Services\Compatibility\Part;

/** A product that could go into a recommended build: on sale, in stock, with its price as the shop charges it now. */
final readonly class Candidate
{
    public function __construct(public Product $product, public Part $part, public int $priceSen) {}

    public function number(string $spec): int|float
    {
        return $this->part->number($spec) ?? 0;
    }
}
