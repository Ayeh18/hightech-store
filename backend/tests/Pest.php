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
use App\Models\PcBuild;
use App\Models\PcBuildItem;
use App\Models\Product;
use App\Models\ProductSpecification;
use App\Models\User;

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

/*
| Shared helpers for cart and checkout tests.
*/

function shopper(): User
{
    $user = User::factory()->create();
    $user->assignRole('customer');

    return $user;
}

function addToCart(User $user, Product $product, int $quantity = 1)
{
    return test()->actingAs($user)->postJson('/api/cart/products', ['product_id' => $product->id, 'quantity' => $quantity]);
}

function cartOf(User $user)
{
    return test()->actingAs($user)->getJson('/api/cart');
}

/*
| A complete, compatible PC for cart and checkout tests.
*/

/**
 * A complete, compatible PC (AM5 + DDR5, a roomy case, a strong PSU), 20 of each part in stock.
 *
 * @return array<string, Product> keyed by slot value
 */
function compatiblePc(): array
{
    return [
        'cpu' => specPart(BuilderSlot::Cpu, ['socket' => 'AM5', 'tdp_watts' => 65, 'integrated_graphics' => 'Yes', 'cooler_included' => 'Yes'], 80000),
        'motherboard' => specPart(BuilderSlot::Motherboard, ['socket' => 'AM5', 'ram_type' => 'DDR5', 'form_factor' => 'ATX', 'ram_slots' => 4, 'max_ram_gb' => 192, 'm2_slots' => 3], 60000),
        'ram' => specPart(BuilderSlot::Ram, ['ram_type' => 'DDR5', 'modules' => 2, 'capacity_gb' => 32], 30000),
        'storage' => specPart(BuilderSlot::Storage, ['form_factor' => 'M.2 2280'], 25000),
        'psu' => specPart(BuilderSlot::Psu, ['wattage' => 750, 'form_factor' => 'ATX'], 40000),
        'case' => specPart(BuilderSlot::Case_, ['supported_form_factors' => ['ATX'], 'gpu_max_length_mm' => 350, 'cpu_cooler_max_height_mm' => 170, 'psu_form_factor' => 'ATX'], 35000),
    ];
}

function pcPayload(array $pc, array $extra = []): array
{
    return [
        'items' => array_map(fn ($product, $slot) => buildItem(BuilderSlot::from($slot), $product), $pc, array_keys($pc)),
        ...$extra,
    ];
}

function addPc(User $user, array $pc, array $extra = [])
{
    return test()->actingAs($user)->postJson('/api/cart/builds', pcPayload($pc, $extra));
}

function savedPc(User $user, array $pc, string $name = 'Gaming PC'): PcBuild
{
    $build = PcBuild::factory()->for($user)->create(['name' => $name]);

    foreach ($pc as $slot => $product) {
        PcBuildItem::factory()->create(['pc_build_id' => $build->id, 'product_id' => $product->id, 'slot' => $slot]);
    }

    return $build;
}
