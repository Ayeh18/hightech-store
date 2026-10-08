<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\SpecDefinition;
use App\Models\User;

function userWithRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** A category with a required enum spec and an optional number spec. */
function gpuSetup(): array
{
    $category = Category::factory()->create(['slug' => 'gpu', 'name' => 'GPU']);
    SpecDefinition::factory()->for($category)->enum(['NVIDIA', 'AMD'])->create(['key' => 'maker', 'label' => 'Maker', 'sort_order' => 1]);
    SpecDefinition::factory()->for($category)->number('mm')->create(['key' => 'length_mm', 'label' => 'Length', 'is_required' => false, 'sort_order' => 2]);

    return [$category, Brand::factory()->create()];
}

function productPayload(Category $category, Brand $brand, array $overrides = []): array
{
    return array_merge([
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'sku' => 'GPU-TEST-001',
        'name' => 'Test Graphics Card',
        'price' => 149900,
        'status' => 'active',
        'specs' => ['maker' => 'NVIDIA', 'length_mm' => 227],
    ], $overrides);
}

it('rejects guests and customers from managing products', function () {
    [$category, $brand] = gpuSetup();
    $payload = productPayload($category, $brand);

    $this->postJson('/api/products', $payload)->assertUnauthorized();
    $this->actingAs(userWithRole('customer'))->postJson('/api/products', $payload)->assertForbidden();
    $this->actingAs(userWithRole('customer'))->getJson('/api/admin/products')->assertForbidden();
    expect(Product::count())->toBe(0);
});

it('creates a product with specs, an empty inventory row and an audit entry', function () {
    [$category, $brand] = gpuSetup();
    $admin = userWithRole('admin');

    $response = $this->actingAs($admin)->postJson('/api/products', productPayload($category, $brand))
        ->assertCreated()
        ->assertJsonPath('data.name', 'Test Graphics Card')
        ->assertJsonPath('data.price.formatted', 'RM 1,499.00')
        ->assertJsonPath('data.slug', 'test-graphics-card')
        ->assertJsonPath('data.specifications.0.display', 'NVIDIA')
        ->assertJsonPath('data.specifications.1.display', '227 mm')
        ->assertJsonPath('data.inventory.stock', 0);

    $product = Product::findOrFail($response->json('data.id'));
    expect($product->price->sen)->toBe(149900)
        ->and(Inventory::where('product_id', $product->id)->value('stock'))->toBe(0)
        ->and(AuditLog::where('action', 'product.created')->where('user_id', $admin->id)->where('subject_id', $product->id)->exists())->toBeTrue();
});

it('lets staff create products but not manage users-only permissions', function () {
    [$category, $brand] = gpuSetup();

    $this->actingAs(userWithRole('staff'))->postJson('/api/products', productPayload($category, $brand))->assertCreated();
});

it('generates unique slugs for products with the same name', function () {
    [$category, $brand] = gpuSetup();
    $admin = userWithRole('admin');

    $this->actingAs($admin)->postJson('/api/products', productPayload($category, $brand, ['sku' => 'A-1']))->assertCreated();
    $this->actingAs($admin)->postJson('/api/products', productPayload($category, $brand, ['sku' => 'A-2']))
        ->assertCreated()
        ->assertJsonPath('data.slug', 'test-graphics-card-2');
});

it('validates product fields', function (array $overrides, string $field) {
    [$category, $brand] = gpuSetup();

    $this->actingAs(userWithRole('admin'))->postJson('/api/products', productPayload($category, $brand, $overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'zero price' => [['price' => 0], 'price'],
    'float price' => [['price' => 12.5], 'price'],
    'string price' => [['price' => 'RM 100'], 'price'],
    'discount not below price' => [['discount_price' => 149900], 'discount_price'],
    'bad sku characters' => [['sku' => 'has space!'], 'sku'],
    'unknown category' => [['category_id' => 9999], 'category_id'],
    'unknown brand' => [['brand_id' => 9999], 'brand_id'],
    'invalid status' => [['status' => 'live'], 'status'],
]);

it('rejects a duplicate sku', function () {
    [$category, $brand] = gpuSetup();
    Product::factory()->create(['sku' => 'GPU-TEST-001']);

    $this->actingAs(userWithRole('admin'))->postJson('/api/products', productPayload($category, $brand))
        ->assertUnprocessable()->assertJsonValidationErrors('sku');
});

it('validates specs against the category definitions', function (array $specs, string $field) {
    [$category, $brand] = gpuSetup();

    $this->actingAs(userWithRole('admin'))->postJson('/api/products', productPayload($category, $brand, ['specs' => $specs]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'required spec missing' => [['length_mm' => 200], 'specs.maker'],
    'enum value not allowed' => [['maker' => 'Matrox'], 'specs.maker'],
    'number is text' => [['maker' => 'AMD', 'length_mm' => 'long'], 'specs.length_mm'],
    'negative number' => [['maker' => 'AMD', 'length_mm' => -5], 'specs.length_mm'],
    'unknown spec key' => [['maker' => 'AMD', 'color' => 'red'], 'specs.color'],
]);

it('requires specs when creating a product', function () {
    [$category, $brand] = gpuSetup();
    $payload = productPayload($category, $brand);
    unset($payload['specs']);

    $this->actingAs(userWithRole('admin'))->postJson('/api/products', $payload)->assertUnprocessable()->assertJsonValidationErrors('specs');
});

it('updates a product and records a dedicated audit entry when the price changes', function () {
    [$category, $brand] = gpuSetup();
    $admin = userWithRole('admin');
    $id = $this->actingAs($admin)->postJson('/api/products', productPayload($category, $brand))->json('data.id');

    $this->actingAs($admin)->putJson("/api/products/{$id}", ['price' => 139900, 'name' => 'Renamed Card'])
        ->assertOk()
        ->assertJsonPath('data.price.amount', 139900)
        ->assertJsonPath('data.name', 'Renamed Card');

    $price = AuditLog::where('action', 'product.price_changed')->firstOrFail();
    expect($price->old_values)->toBe(['price' => 149900, 'discount_price' => null])
        ->and($price->new_values)->toBe(['price' => 139900, 'discount_price' => null])
        ->and($price->user_id)->toBe($admin->id);

    $updated = AuditLog::where('action', 'product.updated')->firstOrFail();
    expect($updated->old_values)->toBe(['name' => 'Test Graphics Card'])->and($updated->new_values)->toBe(['name' => 'Renamed Card']);
});

it('does not write an audit entry when nothing changed', function () {
    [$category, $brand] = gpuSetup();
    $admin = userWithRole('admin');
    $id = $this->actingAs($admin)->postJson('/api/products', productPayload($category, $brand))->json('data.id');
    AuditLog::query()->delete();

    $this->actingAs($admin)->putJson("/api/products/{$id}", ['name' => 'Test Graphics Card', 'price' => 149900])->assertOk();

    expect(AuditLog::count())->toBe(0);
});

it('keeps the discount below the price on partial updates', function () {
    $product = Product::factory()->priced(100000, 90000)->create();
    $admin = userWithRole('admin');

    $this->actingAs($admin)->putJson("/api/products/{$product->id}", ['discount_price' => 100000])->assertUnprocessable()->assertJsonValidationErrors('discount_price');
    $this->actingAs($admin)->putJson("/api/products/{$product->id}", ['price' => 80000])->assertUnprocessable()->assertJsonValidationErrors('price');
    $this->actingAs($admin)->putJson("/api/products/{$product->id}", ['discount_price' => null])->assertOk()->assertJsonPath('data.on_sale', false);
});

it('replaces specs on update and requires them when the category changes', function () {
    [$category, $brand] = gpuSetup();
    $admin = userWithRole('admin');
    $id = $this->actingAs($admin)->postJson('/api/products', productPayload($category, $brand))->json('data.id');

    $this->actingAs($admin)->putJson("/api/products/{$id}", ['specs' => ['maker' => 'AMD']])
        ->assertOk()
        ->assertJsonCount(1, 'data.specifications')
        ->assertJsonPath('data.specifications.0.display', 'AMD');

    $other = Category::factory()->create();
    $this->actingAs($admin)->putJson("/api/products/{$id}", ['category_id' => $other->id])
        ->assertUnprocessable()->assertJsonValidationErrors('specs');
});

it('soft deletes a product, hides it from the shop and audits the deletion', function () {
    $product = Product::factory()->create();
    $admin = userWithRole('admin');

    $this->actingAs($admin)->deleteJson("/api/products/{$product->id}")->assertNoContent();

    expect(Product::find($product->id))->toBeNull()
        ->and(Product::withTrashed()->find($product->id))->not->toBeNull()
        ->and(AuditLog::where('action', 'product.deleted')->exists())->toBeTrue();
    $this->getJson('/api/products')->assertJsonCount(0, 'data');
});

it('lets admins see drafts and filter the admin list by status', function () {
    Product::factory()->create();
    $draft = Product::factory()->draft()->create();
    $admin = userWithRole('admin');

    $this->actingAs($admin)->getJson('/api/admin/products')->assertJsonCount(2, 'data');
    $this->actingAs($admin)->getJson('/api/admin/products?status=draft')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $draft->id);
    $this->actingAs($admin)->getJson("/api/products/{$draft->id}")->assertOk();
});
