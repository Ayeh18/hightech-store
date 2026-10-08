<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSpecification;
use App\Models\SpecDefinition;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** Builds a tiny "CPU" category with a socket (enum) and cores (number) spec. */
function cpuCategory(): Category
{
    $category = Category::factory()->create(['slug' => 'cpu', 'name' => 'CPU', 'builder_slot' => 'cpu']);
    SpecDefinition::factory()->for($category)->enum(['AM4', 'AM5'])->filterable()->create(['key' => 'socket', 'label' => 'Socket', 'show_on_card' => true]);
    SpecDefinition::factory()->for($category)->number()->filterable()->create(['key' => 'cores', 'label' => 'Cores']);

    return $category;
}

function cpu(Category $category, string $name, int $sen, string $socket, int $cores, array $attributes = []): Product
{
    $product = Product::factory()->for($category)->create(['name' => $name, 'price' => Money::fromSen($sen), ...$attributes]);
    ProductSpecification::create(['product_id' => $product->id, 'key' => 'socket', 'value_string' => $socket]);
    ProductSpecification::create(['product_id' => $product->id, 'key' => 'cores', 'value_number' => $cores]);

    return $product;
}

it('lists only published products in active categories', function () {
    $visible = Product::factory()->create();
    Product::factory()->draft()->create();
    Product::factory()->archived()->create();
    Product::factory()->for(Category::factory()->inactive())->create();

    $this->getJson('/api/products')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $visible->id);
});

it('returns money as sen plus a formatted string and never trusts floats', function () {
    Product::factory()->priced(460250, 429900)->create();

    $this->getJson('/api/products')
        ->assertJsonPath('data.0.price.amount', 460250)
        ->assertJsonPath('data.0.price.formatted', 'RM 4,602.50')
        ->assertJsonPath('data.0.effective_price.formatted', 'RM 4,299.00')
        ->assertJsonPath('data.0.on_sale', true);
});

it('paginates and caps the page size', function () {
    Product::factory()->count(15)->create();

    $this->getJson('/api/products?per_page=10')
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('meta.total', 15)
        ->assertJsonPath('meta.last_page', 2);

    $this->getJson('/api/products?per_page=500')->assertUnprocessable()->assertJsonValidationErrors('per_page');
});

it('searches name, sku, brand and category and requires every word to match', function () {
    $cpu = Category::factory()->create(['name' => 'Processors']);
    $amd = Brand::factory()->create(['name' => 'Acme Silicon']);
    $match = Product::factory()->for($cpu)->for($amd)->create(['name' => 'Turbo 9000', 'sku' => 'ZZZ-0001']);
    Product::factory()->create(['name' => 'Something Else']);

    $find = fn (string $q) => collect($this->getJson('/api/products?q='.urlencode($q))->assertOk()->json('data'))->pluck('id')->all();

    expect($find('turbo'))->toBe([$match->id])         // name
        ->and($find('zzz-0001'))->toBe([$match->id])    // sku
        ->and($find('acme'))->toBe([$match->id])        // brand
        ->and($find('processors'))->toBe([$match->id])  // category
        ->and($find('turbo acme'))->toBe([$match->id])  // all words, any field
        ->and($find('turbo banana'))->toBe([]);         // one word missing
});

it('treats search text literally, so wildcards and sql do not leak extra rows', function () {
    Product::factory()->create(['name' => 'Plain Product']);
    Product::factory()->create(['name' => '100% Real']);

    $names = fn (string $q) => collect($this->getJson('/api/products?q='.urlencode($q))->assertOk()->json('data'))->pluck('name')->all();

    expect($names('%'))->toBe(['100% Real'])
        ->and($names('_'))->toBe([])
        ->and($names("'; DROP TABLE products; --"))->toBe([])
        ->and(DB::table('products')->count())->toBe(2);
});

it('filters by category slug, brand and price range on the effective price', function () {
    $category = Category::factory()->create(['slug' => 'gpu']);
    $brandA = Brand::factory()->create(['slug' => 'brand-a']);
    $cheap = Product::factory()->for($category)->for($brandA)->priced(10000)->create();
    // Regular price 300 but discounted to 150: must count as 150.
    $sale = Product::factory()->for($category)->for($brandA)->priced(30000, 15000)->create();
    Product::factory()->for($category)->priced(90000)->create();
    Product::factory()->priced(10000)->create(); // other category

    $ids = fn (string $qs) => collect($this->getJson("/api/products?{$qs}")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

    expect($ids('category=gpu&brands[]=brand-a'))->toBe(collect([$cheap->id, $sale->id])->sort()->values()->all())
        ->and($ids('category=gpu&price_min=12000&price_max=20000'))->toBe([$sale->id])
        ->and($ids('category=gpu&price_max=10000'))->toBe([$cheap->id]);
});

it('filters by stock availability using stock minus reserved', function () {
    $available = Product::factory()->stock(5)->create();
    Product::factory()->outOfStock()->create();
    Product::factory()->stock(3, reserved: 3)->create(); // everything is reserved by unpaid orders

    $this->getJson('/api/products?in_stock=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $available->id);
});

it('filters by specifications defined in the database', function () {
    $category = cpuCategory();
    $am5Fast = cpu($category, 'Fast AM5', 90000, 'AM5', 8);
    cpu($category, 'Slow AM5', 50000, 'AM5', 4);
    cpu($category, 'Old AM4', 40000, 'AM4', 8);

    $ids = fn (string $qs) => collect($this->getJson("/api/products?category=cpu&{$qs}")->assertOk()->json('data'))->pluck('id')->all();

    expect($ids('specs[socket][]=AM5&specs[cores][min]=8'))->toBe([$am5Fast->id])
        ->and($ids('specs[socket][]=AM4'))->toHaveCount(1)
        ->and($ids('specs[socket][]=AM4&specs[socket][]=AM5'))->toHaveCount(3)
        ->and($ids('specs[cores][max]=4'))->toHaveCount(1)
        ->and($ids('specs[bogus][]=x'))->toHaveCount(3); // unknown keys are ignored, not an error
});

it('sorts by price, newest and popularity', function () {
    $mid = Product::factory()->priced(20000)->create(['sold_count' => 50, 'created_at' => now()->subDays(2)]);
    $low = Product::factory()->priced(10000)->create(['sold_count' => 5, 'created_at' => now()->subDays(3)]);
    $high = Product::factory()->priced(30000)->create(['sold_count' => 99, 'created_at' => now()->subDay()]);

    $order = fn (string $sort) => collect($this->getJson("/api/products?sort={$sort}")->json('data'))->pluck('id')->all();

    expect($order('price_asc'))->toBe([$low->id, $mid->id, $high->id])
        ->and($order('price_desc'))->toBe([$high->id, $mid->id, $low->id])
        ->and($order('newest'))->toBe([$high->id, $mid->id, $low->id])
        ->and($order('popular'))->toBe([$high->id, $mid->id, $low->id]);

    $this->getJson('/api/products?sort=random')->assertUnprocessable();
});

it('does not run extra queries per product (no N+1)', function () {
    $category = cpuCategory();
    foreach (range(1, 10) as $i) {
        cpu($category, "CPU {$i}", 10000 + $i, 'AM5', 6);
    }

    DB::enableQueryLog();
    $this->getJson('/api/products?per_page=24')->assertOk()->assertJsonCount(10, 'data');
    $withTen = count(DB::getQueryLog());

    foreach (range(11, 20) as $i) {
        cpu($category, "CPU {$i}", 10000 + $i, 'AM5', 6);
    }

    DB::flushQueryLog();
    $this->getJson('/api/products?per_page=24')->assertOk()->assertJsonCount(20, 'data');

    expect(count(DB::getQueryLog()))->toBe($withTen);
});

it('shows spec labels and units from the database on the detail page', function () {
    $category = cpuCategory();
    $product = cpu($category, 'Detail CPU', 99900, 'AM5', 6);

    $this->getJson("/api/products/{$product->slug}")
        ->assertOk()
        ->assertJsonPath('data.specifications.0.label', 'Socket')
        ->assertJsonPath('data.specifications.0.display', 'AM5')
        ->assertJsonPath('data.specifications.1.display', '6');

    // The numeric id works too.
    $this->getJson("/api/products/{$product->id}")->assertOk()->assertJsonPath('data.slug', $product->slug);
});

it('hides draft, archived and deleted products from the detail endpoint', function () {
    $draft = Product::factory()->draft()->create();
    $deleted = Product::factory()->create();
    $deleted->delete();

    $this->getJson("/api/products/{$draft->id}")->assertNotFound();
    $this->getJson("/api/products/{$deleted->id}")->assertNotFound();
    $this->getJson('/api/products/does-not-exist')->assertNotFound();
});

it('only exposes exact stock numbers to staff who manage inventory', function () {
    $product = Product::factory()->stock(8, reserved: 2)->create();

    $this->getJson("/api/products/{$product->id}")
        ->assertOk()
        ->assertJsonPath('data.in_stock', true)
        ->assertJsonMissingPath('data.inventory');

    $staff = User::factory()->create();
    $staff->assignRole('staff');

    $this->actingAs($staff)->getJson("/api/products/{$product->id}")
        ->assertJsonPath('data.inventory.stock', 8)
        ->assertJsonPath('data.inventory.available', 6);
});

it('warns about low stock without revealing the quantity', function () {
    $product = Product::factory()->stock(3)->create(); // threshold is 5

    $this->getJson("/api/products/{$product->id}")
        ->assertJsonPath('data.low_stock', true)
        ->assertJsonPath('data.in_stock', true);
});
