<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSpecification;
use App\Models\SpecDefinition;
use App\Models\User;

function admin(): User
{
    $user = User::factory()->create();
    $user->assignRole('admin');

    return $user;
}

it('creates a category without any code change and derives its slug', function () {
    $this->actingAs(admin())->postJson('/api/categories', ['name' => 'Capture Cards', 'builder_slot' => null])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'capture-cards');

    $this->getJson('/api/categories')->assertJsonFragment(['slug' => 'capture-cards']);
    expect(AuditLog::where('action', 'category.created')->exists())->toBeTrue();
});

it('validates categories: unique slug and a builder slot used only once', function () {
    Category::factory()->create(['slug' => 'cpu', 'name' => 'CPU', 'builder_slot' => 'cpu']);

    $this->actingAs(admin())->postJson('/api/categories', ['name' => 'CPU'])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->actingAs(admin())->postJson('/api/categories', ['name' => 'Processors', 'builder_slot' => 'cpu'])->assertUnprocessable()->assertJsonValidationErrors('builder_slot');
    $this->actingAs(admin())->postJson('/api/categories', ['name' => 'Other', 'builder_slot' => 'toaster'])->assertUnprocessable()->assertJsonValidationErrors('builder_slot');
});

it('updates and audits a category, and blocks deleting one that still has products', function () {
    $category = Category::factory()->create(['name' => 'Old']);
    $admin = admin();

    $this->actingAs($admin)->putJson("/api/categories/{$category->slug}", ['name' => 'New', 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    expect(AuditLog::where('action', 'category.updated')->firstOrFail()->new_values)->toBe(['name' => 'New', 'is_active' => false]);

    Product::factory()->for($category)->create();
    $this->actingAs($admin)->deleteJson("/api/categories/{$category->slug}")->assertUnprocessable()->assertJsonValidationErrors('category');

    $empty = Category::factory()->create();
    $this->actingAs($admin)->deleteJson("/api/categories/{$empty->slug}")->assertNoContent();
});

it('shows inactive categories to category managers only', function () {
    $category = Category::factory()->inactive()->create();

    $this->getJson("/api/categories/{$category->slug}")->assertNotFound();
    $this->actingAs(admin())->getJson("/api/categories/{$category->slug}")->assertOk();
});

it('manages brands and refuses to delete a brand that has products', function () {
    $admin = admin();

    $this->actingAs($admin)->postJson('/api/brands', ['name' => 'Acme Corp'])->assertCreated()->assertJsonPath('data.slug', 'acme-corp');
    $this->actingAs($admin)->postJson('/api/brands', ['name' => 'Acme Corp'])->assertUnprocessable();

    $brand = Brand::where('slug', 'acme-corp')->firstOrFail();
    $this->actingAs($admin)->putJson("/api/brands/{$brand->slug}", ['name' => 'Acme Corporation'])->assertOk();

    Product::factory()->for($brand)->create();
    $this->actingAs($admin)->deleteJson("/api/brands/{$brand->slug}")->assertUnprocessable();
});

it('forbids customers from changing categories and brands', function () {
    $customer = User::factory()->create();
    $customer->assignRole('customer');
    $category = Category::factory()->create();

    $this->actingAs($customer)->postJson('/api/categories', ['name' => 'X'])->assertForbidden();
    $this->actingAs($customer)->putJson("/api/categories/{$category->slug}", ['name' => 'X'])->assertForbidden();
    $this->actingAs($customer)->postJson('/api/brands', ['name' => 'X'])->assertForbidden();
});

it('lets staff manage brands and categories but not the permission system', function () {
    $staff = User::factory()->create();
    $staff->assignRole('staff');

    $this->actingAs($staff)->postJson('/api/brands', ['name' => 'Staff Brand'])->assertCreated();
    $this->actingAs($staff)->postJson('/api/categories', ['name' => 'Staff Category'])->assertCreated();
});

it('adds a spec definition to a category, so new products can use it immediately', function () {
    $category = Category::factory()->create(['slug' => 'fans']);
    $admin = admin();

    $this->actingAs($admin)->postJson("/api/categories/{$category->slug}/specs", [
        'key' => 'size_mm', 'label' => 'Size', 'type' => 'number', 'unit' => 'mm', 'is_filterable' => true,
    ])->assertCreated()->assertJsonPath('data.type', 'number');

    $this->getJson("/api/categories/{$category->slug}")->assertJsonPath('data.spec_definitions.0.key', 'size_mm');

    $this->actingAs($admin)->postJson('/api/products', [
        'category_id' => $category->id, 'brand_id' => Brand::factory()->create()->id, 'sku' => 'FAN-1', 'name' => 'Quiet Fan',
        'price' => 5000, 'specs' => ['size_mm' => 120],
    ])->assertCreated()->assertJsonPath('data.specifications.0.display', '120 mm');
});

it('validates spec definitions', function (array $payload, string $field) {
    $category = Category::factory()->create();
    SpecDefinition::factory()->for($category)->create(['key' => 'taken']);

    $this->actingAs(admin())->postJson("/api/categories/{$category->slug}/specs", $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'duplicate key in category' => [['key' => 'taken', 'label' => 'X', 'type' => 'string'], 'key'],
    'uppercase key' => [['key' => 'Socket', 'label' => 'X', 'type' => 'string'], 'key'],
    'unknown type' => [['key' => 'a', 'label' => 'X', 'type' => 'color'], 'type'],
    'enum without options' => [['key' => 'a', 'label' => 'X', 'type' => 'enum'], 'options'],
]);

it('cannot change a spec definition key or type, and deleting one removes its product values', function () {
    $category = Category::factory()->create();
    $definition = SpecDefinition::factory()->for($category)->create(['key' => 'color', 'label' => 'Colour', 'is_required' => false]);
    $product = Product::factory()->for($category)->create();
    ProductSpecification::create(['product_id' => $product->id, 'key' => 'color', 'value_string' => 'red']);
    $admin = admin();

    $this->actingAs($admin)->putJson("/api/spec-definitions/{$definition->id}", ['key' => 'colour'])->assertUnprocessable();
    $this->actingAs($admin)->putJson("/api/spec-definitions/{$definition->id}", ['type' => 'number'])->assertUnprocessable();
    $this->actingAs($admin)->putJson("/api/spec-definitions/{$definition->id}", ['label' => 'Color'])->assertOk()->assertJsonPath('data.label', 'Color');

    $this->actingAs($admin)->deleteJson("/api/spec-definitions/{$definition->id}")->assertNoContent();
    expect(ProductSpecification::where('product_id', $product->id)->count())->toBe(0);
});
