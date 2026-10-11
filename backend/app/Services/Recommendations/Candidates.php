<?php

namespace App\Services\Recommendations;

use App\Enums\BuilderSlot;
use App\Models\Product;
use App\Services\Compatibility\Part;

/**
 * The part of the catalog a recommendation may be made from: products on sale and in stock that belong to a builder
 * slot. A recommender, whether rules or AI, can only ever choose from this list, and its choice is checked against it
 * afterwards. That is what makes it impossible to recommend a product that does not exist or cannot be bought.
 */
final readonly class Candidates
{
    /** @param  array<string, list<Candidate>>  $bySlot  keyed by slot value, cheapest first */
    private function __construct(private array $bySlot) {}

    public static function load(): self
    {
        $products = Product::visible()
            ->with(['brand', 'category.specDefinitions', 'specifications', 'inventory', 'images'])
            ->whereHas('category', fn ($query) => $query->whereNotNull('builder_slot'))
            ->get();

        return self::of($products);
    }

    /** @param  iterable<int, Product>  $products  with category, specifications and inventory loaded */
    public static function of(iterable $products): self
    {
        $bySlot = array_fill_keys(BuilderSlot::values(), []);

        foreach ($products as $product) {
            $slot = $product->category->builder_slot;

            if ($slot instanceof BuilderSlot && ($product->inventory->available ?? 0) > 0) {
                $bySlot[$slot->value][] = new Candidate($product, Part::fromProduct($slot, $product), $product->effective_price->sen);
            }
        }

        foreach ($bySlot as &$list) {
            // Cheapest first, then by id so that the same catalog always gives the same answer.
            usort($list, fn (Candidate $a, Candidate $b) => [$a->priceSen, $a->product->id] <=> [$b->priceSen, $b->product->id]);
        }

        return new self($bySlot);
    }

    /** @return list<Candidate> cheapest first */
    public function for(BuilderSlot $slot): array
    {
        return $this->bySlot[$slot->value];
    }

    public function find(BuilderSlot $slot, int $productId): ?Candidate
    {
        foreach ($this->bySlot[$slot->value] as $candidate) {
            if ($candidate->product->id === $productId) {
                return $candidate;
            }
        }

        return null;
    }
}
