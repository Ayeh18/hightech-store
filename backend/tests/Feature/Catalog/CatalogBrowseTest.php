<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSpecification;
use App\Models\SpecDefinition;

it('lists active categories in order with published product counts', function () {
    $second = Category::factory()->create(['name' => 'Second', 'sort_order' => 2]);
    $first = Category::factory()->create(['name' => 'First', 'sort_order' => 1]);
    Category::factory()->inactive()->create();
    Product::factory()->count(2)->for($first)->create();
    Product::factory()->draft()->for($first)->create();

    $this->getJson('/api/categories')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.slug', $first->slug)
        ->assertJsonPath('data.0.products_count', 2)
        ->assertJsonPath('data.1.slug', $second->slug)
        ->assertJsonPath('data.1.products_count', 0);
});

it('hides inactive categories from customers', function () {
    $category = Category::factory()->inactive()->create();

    $this->getJson("/api/categories/{$category->slug}")->assertNotFound();
    $this->getJson("/api/categories/{$category->slug}/filters")->assertNotFound();
});

it('lists brands with their published product counts', function () {
    $brand = Brand::factory()->create(['name' => 'Acme']);
    Product::factory()->count(3)->for($brand)->create();
    Product::factory()->archived()->for($brand)->create();

    $this->getJson('/api/brands')->assertOk()->assertJsonPath('data.0.name', 'Acme')->assertJsonPath('data.0.products_count', 3);
});

it('builds category filters from the database: brands, price range and spec options with counts', function () {
    $category = Category::factory()->create(['slug' => 'cpu']);
    SpecDefinition::factory()->for($category)->enum(['AM4', 'AM5'])->filterable()->create(['key' => 'socket', 'label' => 'Socket']);
    SpecDefinition::factory()->for($category)->number('GHz')->filterable()->create(['key' => 'boost', 'label' => 'Boost']);
    SpecDefinition::factory()->for($category)->list(['DDR4', 'DDR5'])->filterable()->create(['key' => 'memory', 'label' => 'Memory']);
    SpecDefinition::factory()->for($category)->create(['key' => 'internal_note']); // not filterable: must not appear

    $a = Product::factory()->for($category)->for(Brand::factory()->create(['slug' => 'amd', 'name' => 'AMD']))->priced(50000)->create();
    $b = Product::factory()->for($category)->for(Brand::factory()->create(['slug' => 'intel', 'name' => 'Intel']))->priced(90000, 80000)->create();
    Product::factory()->draft()->for($category)->priced(1)->create(); // drafts must not influence filters

    foreach ([[$a, 'AM5', 4.5, ['DDR5']], [$b, 'AM4', 5.5, ['DDR4', 'DDR5']]] as [$product, $socket, $boost, $memory]) {
        ProductSpecification::create(['product_id' => $product->id, 'key' => 'socket', 'value_string' => $socket]);
        ProductSpecification::create(['product_id' => $product->id, 'key' => 'boost', 'value_number' => $boost]);
        ProductSpecification::create(['product_id' => $product->id, 'key' => 'memory', 'value_json' => $memory]);
    }

    $data = $this->getJson('/api/categories/cpu/filters')->assertOk()->json('data');

    expect($data['price'])->toBe(['min' => 50000, 'max' => 80000])
        ->and(collect($data['brands'])->pluck('count', 'slug')->all())->toBe(['amd' => 1, 'intel' => 1])
        ->and(collect($data['specs'])->pluck('key')->all())->toBe(['socket', 'boost', 'memory']);

    $socket = collect($data['specs'])->firstWhere('key', 'socket');
    $boost = collect($data['specs'])->firstWhere('key', 'boost');
    $memory = collect($data['specs'])->firstWhere('key', 'memory');

    // Options keep the order from the definition (AM4 before AM5), not alphabetical or insertion order.
    expect($socket['options'])->toBe([['value' => 'AM4', 'count' => 1], ['value' => 'AM5', 'count' => 1]])
        ->and($boost)->toMatchArray(['min' => 4.5, 'max' => 5.5, 'unit' => 'GHz'])
        ->and($memory['options'])->toBe([['value' => 'DDR4', 'count' => 1], ['value' => 'DDR5', 'count' => 2]]);
});
