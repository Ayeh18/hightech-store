<?php

namespace Database\Factories;

use App\Enums\CartItemKind;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CartItem> */
class CartItemFactory extends Factory
{
    /** A product line by default; the price it was added at defaults to the product's current price. */
    public function definition(): array
    {
        return [
            'cart_id' => Cart::factory(),
            'kind' => CartItemKind::Product,
            'product_id' => Product::factory(),
            'quantity' => 1,
            'price_when_added' => Money::fromSen(10000),
        ];
    }

    public function forProduct(Product $product, int $quantity = 1): static
    {
        return $this->state([
            'kind' => CartItemKind::Product,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price_when_added' => $product->effective_price,
        ]);
    }
}
