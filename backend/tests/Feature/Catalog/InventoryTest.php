<?php

use App\Models\AuditLog;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;

function stockManager(string $role = 'staff'): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('adjusts stock, writes a movement and an audit entry', function () {
    $product = Product::factory()->stock(10)->create();
    $staff = stockManager();

    $this->actingAs($staff)->postJson("/api/products/{$product->id}/inventory/adjustments", ['change' => 15, 'reason' => 'restock', 'note' => 'PO-1001'])
        ->assertOk()
        ->assertJsonPath('data.stock', 25)
        ->assertJsonPath('data.available', 25);

    $movement = InventoryMovement::where('product_id', $product->id)->firstOrFail();
    expect($movement->change)->toBe(15)
        ->and($movement->stock_after)->toBe(25)
        ->and($movement->user_id)->toBe($staff->id)
        ->and($movement->note)->toBe('PO-1001');

    $audit = AuditLog::where('action', 'inventory.adjusted')->firstOrFail();
    expect($audit->old_values)->toBe(['stock' => 10])->and($audit->new_values)->toBe(['stock' => 25, 'reason' => 'restock']);
});

it('never lets stock go below zero', function () {
    $product = Product::factory()->stock(3)->create();

    $this->actingAs(stockManager())->postJson("/api/products/{$product->id}/inventory/adjustments", ['change' => -4, 'reason' => 'adjustment'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('change');

    expect(Inventory::where('product_id', $product->id)->value('stock'))->toBe(3)
        ->and(InventoryMovement::count())->toBe(0);
});

it('never lets stock drop below units reserved by unpaid orders', function () {
    $product = Product::factory()->stock(10, reserved: 6)->create();
    $url = "/api/products/{$product->id}/inventory/adjustments";

    $this->actingAs(stockManager())->postJson($url, ['change' => -5, 'reason' => 'adjustment'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.change.0', 'Stock cannot go below the 6 unit(s) reserved for unpaid orders.');

    $this->actingAs(stockManager())->postJson($url, ['change' => -4, 'reason' => 'adjustment'])->assertOk()->assertJsonPath('data.available', 0);
});

it('validates adjustment input', function (array $payload) {
    $product = Product::factory()->create();

    $this->actingAs(stockManager())->postJson("/api/products/{$product->id}/inventory/adjustments", $payload)->assertUnprocessable();
})->with([
    'zero change' => [['change' => 0, 'reason' => 'restock']],
    'decimal change' => [['change' => 1.5, 'reason' => 'restock']],
    'unknown reason' => [['change' => 1, 'reason' => 'because']],
    'missing reason' => [['change' => 1]],
]);

it('only lets users with inventory permission adjust stock', function () {
    $product = Product::factory()->create();
    $url = "/api/products/{$product->id}/inventory/adjustments";
    $payload = ['change' => 1, 'reason' => 'restock'];

    $this->postJson($url, $payload)->assertUnauthorized();
    $this->actingAs(stockManager('customer'))->postJson($url, $payload)->assertForbidden();
    $this->actingAs(stockManager('staff'))->postJson($url, $payload)->assertOk();
});

it('is protected by a database constraint even if application code is bypassed', function () {
    $product = Product::factory()->stock(5)->create();

    expect(fn () => Inventory::where('product_id', $product->id)->update(['reserved' => 9]))->toThrow(QueryException::class);
});

it('reports available as stock minus reserved', function () {
    $inventory = Inventory::factory()->make(['stock' => 10, 'reserved' => 4, 'low_stock_threshold' => 5]);

    expect($inventory->available)->toBe(6)->and($inventory->isLowStock())->toBeFalse();

    $inventory->reserved = 7;
    expect($inventory->available)->toBe(3)->and($inventory->isLowStock())->toBeTrue();

    $inventory->reserved = 10;
    expect($inventory->available)->toBe(0)->and($inventory->isLowStock())->toBeFalse();
});
