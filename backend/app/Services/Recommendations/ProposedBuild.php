<?php

namespace App\Services\Recommendations;

use App\Enums\BuilderSlot;

/**
 * What a recommender proposes: a product for some slots, each with a reason. It is a proposal and nothing more. The
 * RecommendationService checks every part of it (does the product exist, is it in stock, do the parts fit together,
 * is it within budget) before a customer sees it.
 */
final readonly class ProposedBuild
{
    /**
     * @param  array<string, array{product_id: int, quantity: int, reason: string}>  $picks  keyed by slot value
     * @param  list<string>  $notes  things worth telling the customer about the build as a whole
     */
    public function __construct(public array $picks, public array $notes = []) {}

    /** @return array<int, array{slot: string, product_id: int, quantity: int}> the shape the build evaluator takes */
    public function selection(): array
    {
        $selection = [];

        // Always in builder order (CPU, motherboard, RAM...), whatever order the recommender worked in.
        foreach (BuilderSlot::cases() as $slot) {
            if (isset($this->picks[$slot->value])) {
                $selection[] = ['slot' => $slot->value, 'product_id' => $this->picks[$slot->value]['product_id'], 'quantity' => $this->picks[$slot->value]['quantity']];
            }
        }

        return $selection;
    }
}
