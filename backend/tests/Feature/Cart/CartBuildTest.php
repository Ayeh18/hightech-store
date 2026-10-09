<?php

use App\Enums\BuilderSlot as Slot;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemComponent;
use App\Models\CompatibilityRule;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductSpecification;
use App\Support\Money;

it('adds a whole PC from the builder and stores its parts and prices as a snapshot', function () {
    $user = shopper();
    $pc = compatiblePc();

    $response = addPc($user, $pc, ['name' => 'My Rig'])->assertCreated()
        ->assertJsonPath('data.items.0.kind', 'build')
        ->assertJsonPath('data.items.0.name', 'My Rig')
        ->assertJsonPath('data.items.0.quantity', 1)
        ->assertJsonPath('data.subtotal.amount', 270000) // 800 + 600 + 300 + 250 + 400 + 350
        ->assertJsonPath('data.items.0.compatibility.status', 'compatible')
        ->assertJsonPath('data.checkout_ready', true);

    expect($response->json('data.items.0.components'))->toHaveCount(6)
        ->and($response->json('data.items.0.components.0.slot'))->toBe('cpu')
        ->and($response->json('data.items.0.components.0.unit_price.amount'))->toBe(80000);

    $item = CartItem::firstOrFail();
    expect(CartItemComponent::where('cart_item_id', $item->id)->count())->toBe(6)
        ->and($item->price_when_added->sen)->toBe(270000)
        ->and($item->pc_build_id)->toBeNull()
        ->and(CartItemComponent::where('cart_item_id', $item->id)->where('slot', 'cpu')->first()->unit_price->sen)->toBe(80000);
});

it('names a build from the builder "Custom PC" when no name is given', function () {
    addPc(shopper(), compatiblePc())->assertCreated()->assertJsonPath('data.items.0.name', 'Custom PC');
});

it('adds a saved build, keeping its name and a link back to it', function () {
    $user = shopper();
    $build = savedPc($user, compatiblePc(), 'Streaming Rig');

    $this->actingAs($user)->postJson('/api/cart/builds', ['build_id' => $build->id])->assertCreated()
        ->assertJsonPath('data.items.0.name', 'Streaming Rig')
        ->assertJsonPath('data.items.0.source_build_id', $build->id)
        ->assertJsonCount(6, 'data.items.0.components');
});

it('does not let anyone add someone else\'s saved build, or one that does not exist', function () {
    $owner = shopper();
    $build = savedPc($owner, compatiblePc());

    $this->actingAs(shopper())->postJson('/api/cart/builds', ['build_id' => $build->id])->assertNotFound();
    $this->actingAs(shopper())->postJson('/api/cart/builds', ['build_id' => str_repeat('0', 26)])->assertNotFound();
    expect(CartItem::count())->toBe(0);
});

it('requires exactly one of build_id or items', function () {
    $user = shopper();
    $build = savedPc($user, compatiblePc());

    $this->actingAs($user)->postJson('/api/cart/builds', [])->assertUnprocessable();
    $this->actingAs($user)->postJson('/api/cart/builds', [...pcPayload(compatiblePc()), 'build_id' => $build->id])->assertUnprocessable();
});

it('refuses an incomplete build and says which parts are missing', function () {
    $pc = compatiblePc();
    unset($pc['case'], $pc['psu']);

    addPc(shopper(), $pc)->assertUnprocessable()
        ->assertJsonPath('errors.build', ['Choose a Power supply to complete this build.', 'Choose a Case to complete this build.']);
    expect(CartItem::count())->toBe(0);
});

it('refuses a build with an out-of-stock part', function () {
    $pc = compatiblePc();
    $pc['ram']->update(['name' => 'Sold Out RAM']);
    Inventory::where('product_id', $pc['ram']->id)->update(['stock' => 0]);

    addPc(shopper(), $pc)->assertUnprocessable()->assertJsonPath('errors.build.0', 'Sold Out RAM is out of stock.');
});

it('refuses an incompatible build and explains why, because the server re-checks it', function () {
    $pc = compatiblePc();
    $pc['motherboard'] = specPart(Slot::Motherboard, ['socket' => 'AM4', 'ram_type' => 'DDR5', 'form_factor' => 'ATX', 'ram_slots' => 4, 'max_ram_gb' => 192, 'm2_slots' => 3]);

    addPc(shopper(), $pc)->assertUnprocessable()
        ->assertJsonPath('errors.build.0', 'CPU socket AM5 is not compatible with motherboard socket AM4.');
    expect(CartItem::count())->toBe(0);
});

it('refuses a saved build that has become incompatible since it was saved', function () {
    $user = shopper();
    $pc = compatiblePc();
    $build = savedPc($user, $pc);
    // The product data changes after the customer saved the build: the board now claims a different socket.
    ProductSpecification::where('product_id', $pc['motherboard']->id)->where('key', 'socket')->update(['value_string' => 'LGA1700']);

    $this->actingAs($user)->postJson('/api/cart/builds', ['build_id' => $build->id])->assertUnprocessable()
        ->assertJsonPath('errors.build.0', 'CPU socket AM5 is not compatible with motherboard socket LGA1700.');
});

it('accepts a build that only has warnings', function () {
    $pc = compatiblePc();
    $pc['psu'] = specPart(Slot::Psu, ['wattage' => 150, 'form_factor' => 'ATX'], 40000); // covers 145 W but little headroom

    addPc(shopper(), $pc)->assertCreated()->assertJsonPath('data.items.0.compatibility.status', 'warning')->assertJsonPath('data.checkout_ready', true);
});

it('ignores prices sent by the client for a build', function () {
    $pc = compatiblePc();
    $payload = pcPayload($pc);
    $payload['items'][0]['price'] = 1;
    $payload['items'][0]['unit_price'] = 1;

    $this->actingAs(shopper())->postJson('/api/cart/builds', [...$payload, 'subtotal' => 1, 'total' => 1])->assertCreated()->assertJsonPath('data.subtotal.amount', 270000);
});

it('keeps the snapshot when prices change: pays the current price and warns about the change', function () {
    $user = shopper();
    $pc = compatiblePc();
    addPc($user, $pc);

    $pc['cpu']->update(['price' => Money::fromSen(90000)]); // +100.00

    $response = cartOf($user)->assertOk();
    $cpu = collect($response->json('data.items.0.components'))->firstWhere('slot', 'cpu');

    expect($cpu['price_when_added']['amount'])->toBe(80000)       // what it was
        ->and($cpu['unit_price']['amount'])->toBe(90000)          // what it is now
        ->and($response->json('data.subtotal.amount'))->toBe(280000)
        ->and($response->json('data.items.0.issues.0.code'))->toBe('price_changed')
        ->and($response->json('data.items.0.issues.0.message'))->toBe('Custom PC: the price went up from RM 2,700.00 to RM 2,800.00 since you added it.')
        ->and($response->json('data.checkout_ready'))->toBeTrue();

    $this->actingAs($user)->postJson('/api/cart/accept-prices')->assertJsonPath('data.has_price_changes', false);
});

it('is not affected by later edits to or deletion of the saved build', function () {
    $user = shopper();
    $pc = compatiblePc();
    $build = savedPc($user, $pc, 'Original');
    $this->actingAs($user)->postJson('/api/cart/builds', ['build_id' => $build->id])->assertCreated();

    $build->items()->delete();
    $build->update(['name' => 'Edited']);
    cartOf($user)->assertJsonPath('data.items.0.name', 'Original')->assertJsonCount(6, 'data.items.0.components');

    $build->delete();
    cartOf($user)->assertOk()->assertJsonCount(6, 'data.items.0.components')->assertJsonPath('data.items.0.source_build_id', null);
});

it('checks the build again every time the cart is read', function () {
    $user = shopper();
    $pc = compatiblePc();
    addPc($user, $pc);

    // An admin tightens a rule: the PSU must now be enormous.
    CompatibilityRule::create(['key' => 'psu_covers_estimated_load', 'is_enabled' => true, 'severity' => 'error', 'params' => ['base_system_watts' => 900]]);

    $response = cartOf($user);

    expect($response->json('data.items.0.compatibility.status'))->toBe('incompatible')
        ->and($response->json('data.items.0.issues.0.code'))->toBe('incompatible')
        ->and($response->json('data.items.0.issues.0.message'))->toContain('power supply is below the estimated system draw')
        ->and($response->json('data.checkout_ready'))->toBeFalse();
});

it('flags a build when one of its parts is withdrawn, and leaves the PC out of the total', function () {
    $user = shopper();
    $pc = compatiblePc();
    addPc($user, $pc);
    $pc['storage']->update(['name' => 'Retired SSD']);
    $pc['storage']->delete();

    $response = cartOf($user);

    expect($response->json('data.items.0.issues.0.message'))->toBe('Retired SSD is no longer available.')
        ->and($response->json('data.items.0.issues.0.slot'))->toBe('storage')
        ->and($response->json('data.subtotal.amount'))->toBe(0)
        ->and($response->json('data.checkout_ready'))->toBeFalse();
});

it('counts a part once per place it is used, so one unit of stock cannot be sold twice', function () {
    $user = shopper();
    $pc = compatiblePc();
    $gpu = specPart(Slot::Gpu, ['length_mm' => 300, 'tdp_watts' => 100, 'recommended_psu_watts' => 500], 90000);
    Inventory::where('product_id', $gpu->id)->update(['stock' => 2]);
    addPc($user, [...$pc, 'gpu' => $gpu])->assertCreated(); // uses 1 of the 2 GPUs

    // Adding two more GPUs on their own would need 3 in total.
    addToCart($user, $gpu, 2)->assertUnprocessable()->assertJsonPath('errors.quantity.0', 'Only 1 more of '.$gpu->name.' can be added (2 in stock).');
    addToCart($user, $gpu, 1)->assertCreated();
    addToCart($user, $gpu, 1)->assertUnprocessable();

    // If stock then drops, BOTH lines are flagged.
    Inventory::where('product_id', $gpu->id)->update(['stock' => 1]);
    $response = cartOf($user);
    expect($response->json('data.items.0.issues.0.code'))->toBe('insufficient_stock')
        ->and($response->json('data.items.1.issues.0.code'))->toBe('insufficient_stock')
        ->and($response->json('data.checkout_ready'))->toBeFalse();
});

it('changes the number of copies of a PC, within stock and the limit', function () {
    $user = shopper();
    $pc = compatiblePc();
    Inventory::where('product_id', $pc['cpu']->id)->update(['stock' => 2]);
    addPc($user, $pc);
    $item = CartItem::firstOrFail();

    $this->actingAs($user)->putJson("/api/cart/items/{$item->id}", ['quantity' => 2])->assertOk()
        ->assertJsonPath('data.items.0.quantity', 2)->assertJsonPath('data.subtotal.amount', 540000);
    // The CPU has only 2 in stock, so a third copy is refused.
    $this->actingAs($user)->putJson("/api/cart/items/{$item->id}", ['quantity' => 3])->assertUnprocessable()->assertJsonValidationErrors('quantity');
    expect($item->fresh()->quantity)->toBe(2);
});

it('caps how many copies of one PC can be ordered', function () {
    $user = shopper();
    $pc = compatiblePc();
    foreach ($pc as $product) {
        Inventory::where('product_id', $product->id)->update(['stock' => 100]);
    }

    addPc($user, $pc, ['quantity' => 6])->assertUnprocessable();
    addPc($user, $pc, ['quantity' => 5])->assertCreated()->assertJsonPath('data.items.0.quantity', 5);
});

it('removes a build line together with its snapshot', function () {
    $user = shopper();
    addPc($user, compatiblePc());

    $this->actingAs($user)->deleteJson('/api/cart/items/'.CartItem::firstOrFail()->id)->assertOk()->assertJsonCount(0, 'data.items');

    expect(CartItemComponent::count())->toBe(0);
});

it('keeps builds private to their cart owner', function () {
    $user = shopper();
    addPc($user, compatiblePc());
    $item = CartItem::firstOrFail();

    $this->actingAs(shopper())->deleteJson("/api/cart/items/{$item->id}")->assertNotFound();
    $this->actingAs(shopper())->putJson("/api/cart/items/{$item->id}", ['quantity' => 2])->assertNotFound();
    expect(CartItem::count())->toBe(1);
});

it('does not run extra queries per cart line when builds are in the cart (no N+1)', function () {
    $user = shopper();
    // The baseline already holds one line of each kind (a kind with no lines skips its relations entirely).
    addPc($user, compatiblePc());
    addToCart($user, Product::factory()->create());

    DB::enableQueryLog();
    cartOf($user)->assertOk();
    $baseline = count(DB::getQueryLog());

    addPc($user, compatiblePc());
    addPc($user, compatiblePc());
    foreach (Product::factory()->count(3)->create() as $product) {
        addToCart($user, $product);
    }

    DB::flushQueryLog();
    cartOf($user)->assertOk()->assertJsonCount(7, 'data.items');

    expect(count(DB::getQueryLog()))->toBe($baseline);
});

it('mixes single products and builds in one cart and totals them', function () {
    $user = shopper();
    addPc($user, compatiblePc()); // 2700.00
    addToCart($user, Product::factory()->priced(12345)->create(), 2); // 246.90

    cartOf($user)->assertOk()->assertJsonPath('data.subtotal.amount', 294690)->assertJsonPath('data.item_count', 3)->assertJsonPath('data.checkout_ready', true);
    expect(Cart::count())->toBe(1);
});
