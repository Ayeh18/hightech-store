<?php

use App\Enums\BuilderSlot;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Support\Money;

it('lists the builder slots in order with the category that supplies each one', function () {
    partFor(BuilderSlot::Gpu);
    partFor(BuilderSlot::Cpu);
    Category::factory()->inactive()->create(['builder_slot' => 'psu', 'slug' => 'psu']);

    $response = $this->getJson('/api/pc-builder/components')->assertOk();

    // Screen order (cpu before gpu); slots with no active category are left out.
    expect(collect($response->json('data'))->pluck('slot')->all())->toBe(['cpu', 'gpu'])
        ->and($response->json('data.0'))->toMatchArray(['slot' => 'cpu', 'label' => 'CPU', 'required' => true, 'max_quantity' => 1])
        ->and($response->json('data.1'))->toMatchArray(['slot' => 'gpu', 'required' => false]);
});

it('prices a build from database prices using the discount price and quantity', function () {
    $cpu = partFor(BuilderSlot::Cpu, 100000, ['discount_price' => Money::fromSen(90000)]);
    $ram = partFor(BuilderSlot::Ram, 30000);

    $this->postJson('/api/pc-builder/validate', ['items' => [buildItem(BuilderSlot::Ram, $ram, 2), buildItem(BuilderSlot::Cpu, $cpu)]])
        ->assertOk()
        ->assertJsonPath('data.subtotal.amount', 150000) // 900.00 + 2 x 300.00
        ->assertJsonPath('data.subtotal.formatted', 'RM 1,500.00')
        ->assertJsonPath('data.item_count', 3)
        // Lines come back in screen order regardless of the order sent.
        ->assertJsonPath('data.items.0.slot', 'cpu')
        ->assertJsonPath('data.items.0.unit_price.amount', 90000)
        ->assertJsonPath('data.items.1.slot', 'ram')
        ->assertJsonPath('data.items.1.line_total.amount', 60000);
});

it('ignores any price the client sends', function () {
    $cpu = partFor(BuilderSlot::Cpu, 100000);

    $this->postJson('/api/pc-builder/validate', ['items' => [[...buildItem(BuilderSlot::Cpu, $cpu), 'price' => 1, 'unit_price' => 1, 'subtotal' => 1]], 'total' => 1])
        ->assertOk()
        ->assertJsonPath('data.subtotal.amount', 100000);
});

it('reports which required slots are still empty, and treats gpu and cooler as optional', function () {
    $cpu = partFor(BuilderSlot::Cpu);

    $response = $this->postJson('/api/pc-builder/validate', ['items' => [buildItem(BuilderSlot::Cpu, $cpu)]])->assertOk();

    expect($response->json('data.complete'))->toBeFalse()
        ->and($response->json('data.purchasable'))->toBeFalse()
        ->and(collect($response->json('data.missing_slots'))->pluck('slot')->all())->toBe(['motherboard', 'ram', 'storage', 'psu', 'case']);
});

it('marks a build with every required part, in stock, as complete and purchasable', function () {
    [$items] = completeBuild(10000);

    $this->postJson('/api/pc-builder/validate', ['items' => $items])
        ->assertOk()
        ->assertJsonPath('data.complete', true)
        ->assertJsonPath('data.purchasable', true)
        ->assertJsonPath('data.issues', [])
        ->assertJsonPath('data.subtotal.amount', 60000);
});

it('flags out-of-stock parts as errors but still prices them', function () {
    $cpu = partFor(BuilderSlot::Cpu, 50000, ['name' => 'Sold Out CPU']);
    Inventory::where('product_id', $cpu->id)->update(['stock' => 0]);

    $this->postJson('/api/pc-builder/validate', ['items' => [buildItem(BuilderSlot::Cpu, $cpu)]])
        ->assertOk()
        ->assertJsonPath('data.subtotal.amount', 50000)
        ->assertJsonPath('data.items.0.purchasable', false)
        ->assertJsonPath('data.issues.0.code', 'out_of_stock')
        ->assertJsonPath('data.issues.0.severity', 'error')
        ->assertJsonPath('data.issues.0.message', 'Sold Out CPU is out of stock.')
        ->assertJsonPath('data.issues.0.slot', 'cpu');
});

it('flags a quantity larger than the available stock', function () {
    $ram = partFor(BuilderSlot::Ram, 20000, ['name' => 'Scarce RAM'])->loadMissing('inventory');
    Inventory::where('product_id', $ram->id)->update(['stock' => 5, 'reserved' => 3]); // only 2 available

    $this->postJson('/api/pc-builder/validate', ['items' => [buildItem(BuilderSlot::Ram, $ram, 3)]])
        ->assertOk()
        ->assertJsonPath('data.issues.0.code', 'insufficient_stock')
        ->assertJsonPath('data.issues.0.message', 'Only 2 of Scarce RAM available (you selected 3).');

    $this->postJson('/api/pc-builder/validate', ['items' => [buildItem(BuilderSlot::Ram, $ram, 2)]])->assertJsonPath('data.issues', []);
});

it('does not reveal products that are not on sale', function () {
    $draft = partFor(BuilderSlot::Cpu, 99900, ['name' => 'Secret Prototype CPU'])->fill(['status' => 'draft']);
    $draft->save();

    $response = $this->postJson('/api/pc-builder/validate', ['items' => [buildItem(BuilderSlot::Cpu, $draft)]])->assertOk();

    expect($response->json('data.items.0.product'))->toBeNull()
        ->and($response->json('data.items.0.unit_price'))->toBeNull()
        ->and($response->json('data.subtotal.amount'))->toBe(0)
        ->and($response->json('data.issues.0.code'))->toBe('product_unavailable')
        ->and($response->getContent())->not->toContain('Secret Prototype')
        ->and($response->getContent())->not->toContain('99900');
});

it('treats deleted products as unavailable too', function () {
    $cpu = partFor(BuilderSlot::Cpu);
    $cpu->delete();

    $this->postJson('/api/pc-builder/validate', ['items' => [buildItem(BuilderSlot::Cpu, $cpu)]])
        ->assertOk()->assertJsonPath('data.issues.0.code', 'product_unavailable');
});

it('rejects a product placed in the wrong slot', function () {
    $gpu = partFor(BuilderSlot::Gpu, 10000, ['name' => 'Graphics Card X']);

    $this->postJson('/api/pc-builder/validate', ['items' => [buildItem(BuilderSlot::Cpu, $gpu)]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['items.0.product_id' => 'Graphics Card X cannot be used as CPU.']);
});

it('validates the request shape', function (array $payload, string $field) {
    $this->postJson('/api/pc-builder/validate', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'no items' => [[], 'items'],
    'items is not a list' => [['items' => 'cpu'], 'items'],
    'unknown slot' => [['items' => [['slot' => 'toaster', 'product_id' => 1]]], 'items.0.slot'],
    'missing product' => [['items' => [['slot' => 'cpu']]], 'items.0.product_id'],
    'non numeric product' => [['items' => [['slot' => 'cpu', 'product_id' => 'abc']]], 'items.0.product_id'],
    'zero quantity' => [['items' => [['slot' => 'cpu', 'product_id' => 1, 'quantity' => 0]]], 'items.0.quantity'],
    'duplicate slot' => [['items' => [['slot' => 'cpu', 'product_id' => 1], ['slot' => 'cpu', 'product_id' => 2]]], 'items.0.slot'],
]);

it('rejects a product id that does not exist', function () {
    $this->postJson('/api/pc-builder/validate', ['items' => [['slot' => 'cpu', 'product_id' => 999999]]])
        ->assertUnprocessable()->assertJsonValidationErrors('items.0.product_id');
});

it('enforces the per-slot quantity limit: one CPU, up to four RAM kits', function () {
    $cpu = partFor(BuilderSlot::Cpu);
    $ram = partFor(BuilderSlot::Ram);

    $this->postJson('/api/pc-builder/validate', ['items' => [buildItem(BuilderSlot::Cpu, $cpu, 2)]])
        ->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
    $this->postJson('/api/pc-builder/validate', ['items' => [buildItem(BuilderSlot::Ram, $ram, 5)]])
        ->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
    $this->postJson('/api/pc-builder/validate', ['items' => [buildItem(BuilderSlot::Ram, $ram, 4)]])->assertOk();
});

it('does not run extra queries per selected part (no N+1)', function () {
    [$items] = completeBuild();
    $fewer = array_slice($items, 0, 2);

    DB::enableQueryLog();
    $this->postJson('/api/pc-builder/validate', ['items' => $fewer])->assertOk();
    $withTwo = count(DB::getQueryLog());

    DB::flushQueryLog();
    $this->postJson('/api/pc-builder/validate', ['items' => $items])->assertOk();

    expect(count(DB::getQueryLog()))->toBe($withTwo);
});

it('gives hidden products the same response whether they exist or not for guests', function () {
    // Guests cannot probe product ids to learn which unpublished products exist: a missing id is a 422,
    // but an existing hidden product is reported only as "unavailable" with no details.
    $hidden = Product::factory()->draft()->create();

    $this->postJson('/api/pc-builder/validate', ['items' => [['slot' => 'cpu', 'product_id' => $hidden->id]]])
        ->assertOk()->assertJsonPath('data.issues.0.message', 'A selected cpu is no longer available.');
});
