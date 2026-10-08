<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Feature tests hit a real PostgreSQL test database (pc_store_testing), rebuilt inside a
| transaction per test, and always start with the roles/permissions seeded.
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => $this->seed(RolePermissionSeeder::class))
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/*
| Shared test helpers for the PC builder.
*/

use App\Enums\BuilderSlot;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSpecification;

/** A product that belongs in a builder slot (the category for the slot is created once and reused). */
function partFor(BuilderSlot $slot, int $sen = 10000, array $attributes = []): Product
{
    $category = Category::firstOrCreate(
        ['builder_slot' => $slot->value],
        ['slug' => "cat-{$slot->value}", 'name' => ucfirst($slot->value), 'is_active' => true],
    );

    return Product::factory()->for($category)->priced($sen)->create($attributes);
}

/** @return array{slot: string, product_id: int, quantity: int} */
function buildItem(BuilderSlot $slot, Product $product, int $quantity = 1): array
{
    return ['slot' => $slot->value, 'product_id' => $product->id, 'quantity' => $quantity];
}

/** One in-stock part for every required slot: [items payload, products keyed by slot value]. */
function completeBuild(int $sen = 10000): array
{
    $items = [];
    $products = [];

    foreach (array_filter(BuilderSlot::cases(), fn (BuilderSlot $s) => $s->isRequired()) as $slot) {
        $products[$slot->value] = partFor($slot, $sen);
        $items[] = buildItem($slot, $products[$slot->value]);
    }

    return [$items, $products];
}

/**
 * A builder part with specs, e.g. specPart(BuilderSlot::Cpu, ['socket' => 'AM5', 'tdp_watts' => 65]).
 * Strings become text specs, numbers numeric specs and arrays list specs.
 */
function specPart(BuilderSlot $slot, array $specs, int $sen = 10000, array $attributes = []): Product
{
    $product = partFor($slot, $sen, $attributes);

    foreach ($specs as $key => $value) {
        ProductSpecification::create([
            'product_id' => $product->id,
            'key' => $key,
            ...match (true) {
                is_array($value) => ['value_json' => $value],
                is_int($value) || is_float($value) => ['value_number' => $value],
                default => ['value_string' => $value],
            },
        ]);
    }

    return $product->load('specifications');
}
