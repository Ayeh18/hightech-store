<?php

namespace App\Services\Cart;

use App\Support\Money;

/** The cart as the server sees it right now: current prices, stock and compatibility for every line. */
final readonly class EvaluatedCart
{
    /** @param  array<int, CartLine>  $lines */
    public function __construct(public array $lines, public Money $subtotal, public int $itemCount) {}

    /** Something to pay for, and nothing blocking: no withdrawn parts, no stock shortfall, no incompatible build. */
    public function isCheckoutReady(): bool
    {
        if ($this->lines === []) {
            return false;
        }

        foreach ($this->lines as $line) {
            if ($line->hasErrors()) {
                return false;
            }
        }

        return true;
    }
}
