<?php

namespace App\Services\PcBuilder;

use App\Enums\BuilderSlot;
use App\Support\Money;

/**
 * The server's authoritative view of a build: current prices, stock, what is missing and what is wrong.
 * Nothing the browser sends is trusted; everything here is recalculated from the database.
 */
final readonly class PricedBuild
{
    /**
     * @param  array<int, PricedLine>  $lines
     * @param  array<int, BuildIssue>  $issues
     * @param  array<int, BuilderSlot>  $missingSlots  required slots with nothing selected
     */
    public function __construct(
        public array $lines,
        public Money $subtotal,
        public array $issues,
        public array $missingSlots,
    ) {}

    /** All required slots are filled. */
    public function isComplete(): bool
    {
        return $this->missingSlots === [];
    }

    public function hasErrors(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->severity === BuildIssue::ERROR) {
                return true;
            }
        }

        return false;
    }

    /** Complete, every part is on sale and in stock, and no blocking issue exists. Used by the cart (Phase 5). */
    public function isPurchasable(): bool
    {
        return $this->isComplete() && ! $this->hasErrors();
    }
}
