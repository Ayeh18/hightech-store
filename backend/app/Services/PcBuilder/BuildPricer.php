<?php

namespace App\Services\PcBuilder;

use App\Enums\BuilderSlot;
use App\Enums\ProductStatus;
use App\Models\PcBuild;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * Prices a build from the database. This is the only place that decides what a build costs,
 * which parts are still on sale and whether stock covers the quantities.
 */
class BuildPricer
{
    /**
     * Price a selection sent by a client, e.g. [['slot' => 'cpu', 'product_id' => 5, 'quantity' => 1], ...].
     * Products that are not on sale are reported as unavailable without revealing anything about them.
     *
     * @param  array<int, array{slot: string, product_id: int, quantity?: int}>  $selection
     *
     * @throws ValidationException when a product does not exist or does not belong in its slot
     */
    public function priceSelection(array $selection): PricedBuild
    {
        $products = $this->loadProducts(array_column($selection, 'product_id'));
        $entries = [];
        $errors = [];

        foreach (array_values($selection) as $index => $row) {
            $slot = BuilderSlot::from($row['slot']);
            $product = $products[$row['product_id']] ?? null;

            if ($product === null) {
                $errors["items.{$index}.product_id"] = 'This product does not exist.';

                continue;
            }

            if ($this->isOnSale($product) && $product->category->builder_slot !== $slot) {
                $errors["items.{$index}.product_id"] = "{$product->name} cannot be used as {$slot->label()}.";

                continue;
            }

            $entries[] = ['slot' => $slot, 'quantity' => (int) ($row['quantity'] ?? 1), 'product' => $product, 'reveal' => false];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $this->priceEntries($entries);
    }

    /**
     * Price a saved build. The owner already knew these products, so a part that has since been
     * withdrawn is still named (and flagged as unavailable). Needs `items.product.category` and
     * `items.product.inventory` eager loaded.
     */
    public function priceBuild(PcBuild $build): PricedBuild
    {
        $entries = $build->items->map(fn ($item) => [
            'slot' => $item->slot,
            'quantity' => $item->quantity,
            'product' => $item->product,
            'reveal' => true,
        ])->all();

        return $this->priceEntries($entries);
    }

    /**
     * @param  array<int, array{slot: BuilderSlot, quantity: int, product: Product, reveal: bool}>  $entries
     */
    private function priceEntries(array $entries): PricedBuild
    {
        usort($entries, fn ($a, $b) => array_search($a['slot'], BuilderSlot::cases(), true) <=> array_search($b['slot'], BuilderSlot::cases(), true));

        $lines = [];
        $issues = [];
        $subtotal = Money::zero();

        foreach ($entries as $entry) {
            /** @var Product $product */
            $product = $entry['product'];
            $slot = $entry['slot'];
            $quantity = $entry['quantity'];
            $name = $entry['reveal'] ? $product->name : 'A selected '.strtolower($slot->label());

            if (! $this->isOnSale($product)) {
                $issues[] = new BuildIssue('product_unavailable', BuildIssue::ERROR, "{$name} is no longer available.", $slot);
                $lines[] = new PricedLine($slot, $quantity, $entry['reveal'] ? $product : null, null, null, false);

                continue;
            }

            $unit = $product->effective_price;
            $line = $unit->multiply($quantity);
            $subtotal = $subtotal->add($line);
            $available = $product->inventory->available ?? 0;

            if ($available <= 0) {
                $issues[] = new BuildIssue('out_of_stock', BuildIssue::ERROR, "{$product->name} is out of stock.", $slot);
            } elseif ($available < $quantity) {
                $issues[] = new BuildIssue('insufficient_stock', BuildIssue::ERROR, "Only {$available} of {$product->name} available (you selected {$quantity}).", $slot);
            }

            $lines[] = new PricedLine($slot, $quantity, $product, $unit, $line, $available >= $quantity);
        }

        $filled = array_map(fn (array $e) => $e['slot'], $entries);
        $missing = array_values(array_filter(BuilderSlot::cases(), fn (BuilderSlot $s) => $s->isRequired() && ! in_array($s, $filled, true)));

        return new PricedBuild($lines, $subtotal, $issues, $missing);
    }

    private function isOnSale(Product $product): bool
    {
        return ! $product->trashed()
            && $product->status === ProductStatus::Active
            && $product->category->is_active;
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, Product> keyed by id
     */
    private function loadProducts(array $ids): array
    {
        return Product::withTrashed()
            ->with(['brand', 'category.specDefinitions', 'images', 'inventory', 'specifications'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id')
            ->all();
    }
}
