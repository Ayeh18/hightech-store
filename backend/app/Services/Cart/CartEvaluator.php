<?php

namespace App\Services\Cart;

use App\Enums\BuilderSlot;
use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Services\Compatibility\ActiveRules;
use App\Services\Compatibility\BuildContext;
use App\Services\Compatibility\CompatibilityService;
use App\Services\Compatibility\Part;
use App\Services\PcBuilder\BuildIssue;
use App\Support\Money;

/**
 * Re-prices and re-validates the whole cart from the database. Checkout (Phase 6) uses this same class, so the
 * cart page and the checkout can never disagree. Nothing stored in the cart is trusted for money: the stored
 * "price when added" is only used to tell the customer that a price has changed.
 */
class CartEvaluator
{
    /** Everything a product card needs, so one cart page costs a constant number of queries. */
    private const PRODUCT_RELATIONS = ['brand', 'category.specDefinitions', 'images', 'inventory', 'specifications'];

    public function __construct(private readonly CompatibilityService $compatibility) {}

    public function evaluate(Cart $cart, ?ActiveRules $rules = null): EvaluatedCart
    {
        $cart->load($this->relations());
        $items = $cart->items;
        $demand = $this->demand($items->all());
        $rules ??= $this->compatibility->rules();

        $lines = [];
        $subtotal = Money::zero();
        $count = 0;

        foreach ($items as $item) {
            $line = $item->isBuild() ? $this->buildLine($item, $demand, $rules) : $this->productLine($item, $demand);
            $lines[] = $line;
            $count += $item->quantity;

            if ($line->isPriced()) {
                $subtotal = $subtotal->add($line->lineTotal);
            }
        }

        return new EvaluatedCart($lines, $subtotal, $count);
    }

    /** @return array<int, string> */
    private function relations(): array
    {
        $relations = [];

        foreach (self::PRODUCT_RELATIONS as $relation) {
            $relations[] = "items.product.{$relation}";
            $relations[] = "items.components.product.{$relation}";
        }

        return $relations;
    }

    /**
     * Units of each product the whole cart needs, counting single products AND the parts inside builds, so the
     * same graphics card sitting in two lines cannot be sold twice from one unit of stock.
     *
     * @param  array<int, CartItem>  $items
     * @return array<int, int> product id => units
     */
    private function demand(array $items): array
    {
        $demand = [];

        foreach ($items as $item) {
            if ($item->isBuild()) {
                foreach ($item->components as $component) {
                    $demand[$component->product_id] = ($demand[$component->product_id] ?? 0) + $item->quantity * $component->quantity;
                }
            } else {
                $demand[$item->product_id] = ($demand[$item->product_id] ?? 0) + $item->quantity;
            }
        }

        return $demand;
    }

    /** @param  array<int, int>  $demand */
    private function productLine(CartItem $item, array $demand): CartLine
    {
        $product = $item->product;
        $onSale = $this->isOnSale($product);
        $unit = $onSale ? $product->effective_price : null;
        $issues = $this->availabilityIssues($product, $onSale, $demand, null);

        if ($unit !== null && ! $unit->equals($item->price_when_added)) {
            $issues[] = $this->priceChanged($product->name, $item->price_when_added, $unit);
        }

        return new CartLine(
            $item,
            [new CartLinePart(null, $product, 1, $onSale, $unit, null)],
            $unit,
            $unit?->multiply($item->quantity) ?? Money::zero(),
            $issues,
        );
    }

    /** @param  array<int, int>  $demand */
    private function buildLine(CartItem $item, array $demand, ActiveRules $rules): CartLine
    {
        $parts = [];
        $issues = [];
        $unitTotal = Money::zero();
        $allOnSale = true;
        $compatParts = [];

        foreach ($item->components as $component) {
            $product = $component->product;
            $onSale = $this->isOnSale($product);
            $unit = $onSale ? $product->effective_price : null;

            $parts[] = new CartLinePart($component->slot, $product, $component->quantity, $onSale, $unit, $component->unit_price);
            $issues = [...$issues, ...$this->availabilityIssues($product, $onSale, $demand, $component->slot)];
            $compatParts[] = Part::fromProduct($component->slot, $product, $component->quantity);

            if ($unit === null) {
                $allOnSale = false;
            } else {
                $unitTotal = $unitTotal->add($unit->multiply($component->quantity));
            }
        }

        // The build is checked again every time: a product's specs or an admin's rule settings may have changed.
        $report = $this->compatibility->check(BuildContext::of($compatParts), $rules);

        foreach ($report->issues as $problem) {
            if ($problem->severity === 'error') {
                $issues[] = new BuildIssue('incompatible', BuildIssue::ERROR, "{$item->name}: {$problem->message}");
            }
        }

        if ($allOnSale && ! $unitTotal->equals($item->price_when_added)) {
            $issues[] = $this->priceChanged($item->name ?? 'This PC build', $item->price_when_added, $unitTotal);
        }

        $unit = $allOnSale ? $unitTotal : null;

        return new CartLine($item, $parts, $unit, $unit?->multiply($item->quantity) ?? Money::zero(), $issues, $report);
    }

    /**
     * @param  array<int, int>  $demand
     * @return array<int, BuildIssue>
     */
    private function availabilityIssues(Product $product, bool $onSale, array $demand, ?BuilderSlot $slot): array
    {
        if (! $onSale) {
            return [new BuildIssue('product_unavailable', BuildIssue::ERROR, "{$product->name} is no longer available.", $slot)];
        }

        $available = $product->inventory?->available ?? 0;
        $needed = $demand[$product->id] ?? 0;

        if ($available <= 0) {
            return [new BuildIssue('out_of_stock', BuildIssue::ERROR, "{$product->name} is out of stock.", $slot)];
        }

        if ($needed > $available) {
            return [new BuildIssue('insufficient_stock', BuildIssue::ERROR, "Only {$available} of {$product->name} available, but your cart needs {$needed}.", $slot)];
        }

        return [];
    }

    private function priceChanged(string $name, Money $old, Money $new): BuildIssue
    {
        $direction = $new->greaterThan($old) ? 'went up' : 'went down';

        return new BuildIssue('price_changed', BuildIssue::WARNING, "{$name}: the price {$direction} from {$old->format()} to {$new->format()} since you added it.");
    }

    private function isOnSale(Product $product): bool
    {
        return ! $product->trashed()
            && $product->status === ProductStatus::Active
            && $product->category->is_active;
    }
}
