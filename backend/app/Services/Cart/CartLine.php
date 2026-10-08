<?php

namespace App\Services\Cart;

use App\Models\CartItem;
use App\Services\Compatibility\CompatibilityReport;
use App\Services\PcBuilder\BuildIssue;
use App\Support\Money;

/** One line of the cart, priced from the database right now. */
final readonly class CartLine
{
    /**
     * @param  array<int, CartLinePart>  $parts
     * @param  array<int, BuildIssue>  $issues
     */
    public function __construct(
        public CartItem $item,
        public array $parts,
        public ?Money $unitPrice,   // one product, or ONE copy of a build; null when it cannot be priced
        public Money $lineTotal,    // unit price x quantity (zero when it cannot be priced)
        public array $issues,
        public ?CompatibilityReport $compatibility = null, // build lines only
    ) {}

    public function hasErrors(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->severity === BuildIssue::ERROR) {
                return true;
            }
        }

        return false;
    }

    public function isPriced(): bool
    {
        return $this->unitPrice !== null;
    }
}
