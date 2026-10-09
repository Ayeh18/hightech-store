<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderItem> */
class OrderItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'sku' => strtoupper(fake()->bothify('???-####')),
            'name' => fake()->words(3, true),
            'quantity' => 1,
            'unit_price' => Money::fromSen(100000),
            'line_total' => Money::fromSen(100000),
        ];
    }
}
