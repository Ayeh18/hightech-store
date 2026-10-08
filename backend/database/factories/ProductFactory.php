<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'category_id' => Category::factory(),
            'brand_id' => Brand::factory(),
            'sku' => strtoupper(fake()->unique()->bothify('???-####')),
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'name' => Str::title($name),
            'description' => fake()->paragraph(),
            'price' => Money::fromSen(fake()->numberBetween(5_000, 500_000)),
            'discount_price' => null,
            'status' => ProductStatus::Active,
            'weight_grams' => fake()->numberBetween(100, 5000),
            'warranty_months' => 36,
            'warranty_info' => null,
        ];
    }

    /** Every product gets an inventory row (20 units by default), like in the real catalog. */
    public function configure(): static
    {
        return $this->afterCreating(function (Product $product) {
            Inventory::factory()->create(['product_id' => $product->id]);
        });
    }

    public function stock(int $stock, int $reserved = 0): static
    {
        return $this->afterCreating(function (Product $product) use ($stock, $reserved) {
            Inventory::where('product_id', $product->id)->update(['stock' => $stock, 'reserved' => $reserved]);
        });
    }

    public function outOfStock(): static
    {
        return $this->stock(0);
    }

    public function priced(int $sen, ?int $discountSen = null): static
    {
        return $this->state([
            'price' => Money::fromSen($sen),
            'discount_price' => $discountSen === null ? null : Money::fromSen($discountSen),
        ]);
    }

    public function draft(): static
    {
        return $this->state(['status' => ProductStatus::Draft]);
    }

    public function archived(): static
    {
        return $this->state(['status' => ProductStatus::Archived]);
    }
}
