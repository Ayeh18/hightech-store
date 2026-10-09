<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Services\Cart\CartService;
use App\Support\Money;
use Illuminate\Database\QueryException;

it('requires login for every cart endpoint', function () {
    $item = CartItem::factory()->create();

    $this->getJson('/api/cart')->assertUnauthorized();
    $this->deleteJson('/api/cart')->assertUnauthorized();
    $this->postJson('/api/cart/products', ['product_id' => 1])->assertUnauthorized();
    $this->postJson('/api/cart/builds', ['build_id' => str_repeat('a', 26)])->assertUnauthorized();
    $this->putJson("/api/cart/items/{$item->id}", ['quantity' => 2])->assertUnauthorized();
    $this->deleteJson("/api/cart/items/{$item->id}")->assertUnauthorized();
    $this->postJson('/api/cart/accept-prices')->assertUnauthorized();
});

it('starts empty and creates the cart on first use', function () {
    $user = shopper();

    cartOf($user)->assertOk()->assertJsonPath('data.items', [])->assertJsonPath('data.item_count', 0)
        ->assertJsonPath('data.subtotal.amount', 0)->assertJsonPath('data.checkout_ready', false);

    expect(Cart::where('user_id', $user->id)->count())->toBe(1);
    cartOf($user)->assertOk();
    expect(Cart::where('user_id', $user->id)->count())->toBe(1); // still one cart
});

it('adds a product and prices it from the database', function () {
    $user = shopper();
    $gpu = Product::factory()->priced(150000)->create(['name' => 'Test GPU']);

    addToCart($user, $gpu, 2)->assertCreated()
        ->assertJsonPath('data.items.0.kind', 'product')
        ->assertJsonPath('data.items.0.name', 'Test GPU')
        ->assertJsonPath('data.items.0.quantity', 2)
        ->assertJsonPath('data.items.0.unit_price.amount', 150000)
        ->assertJsonPath('data.items.0.line_total.formatted', 'RM 3,000.00')
        ->assertJsonPath('data.subtotal.amount', 300000)
        ->assertJsonPath('data.item_count', 2)
        ->assertJsonPath('data.checkout_ready', true);
});

it('uses the discount price, and ignores any price the client sends', function () {
    $user = shopper();
    $product = Product::factory()->priced(100000, 80000)->create();

    $this->actingAs($user)->postJson('/api/cart/products', [
        'product_id' => $product->id, 'quantity' => 1, 'price' => 1, 'unit_price' => 1, 'subtotal' => 1,
    ])->assertCreated()->assertJsonPath('data.subtotal.amount', 80000);
});

it('keeps one line per product and adds the quantities together', function () {
    $user = shopper();
    $product = Product::factory()->create();

    addToCart($user, $product, 2);
    addToCart($user, $product, 3)->assertJsonPath('data.items.0.quantity', 5)->assertJsonCount(1, 'data.items');

    expect(CartItem::count())->toBe(1);
});

it('limits how many of one product can be ordered', function () {
    $user = shopper();
    $product = Product::factory()->stock(50)->create();
    $max = CartService::MAX_PRODUCT_QUANTITY;

    addToCart($user, $product, $max)->assertCreated();
    addToCart($user, $product, 1)->assertUnprocessable()->assertJsonValidationErrors('quantity');
    $this->actingAs($user)->postJson('/api/cart/products', ['product_id' => $product->id, 'quantity' => $max + 1])->assertUnprocessable();
    expect(CartItem::first()->quantity)->toBe($max);
});

it('cannot add more than is in stock, counting reserved units', function () {
    $user = shopper();
    $product = Product::factory()->stock(5, reserved: 3)->create(['name' => 'Scarce Part']); // 2 available

    addToCart($user, $product, 3)->assertUnprocessable()
        ->assertJsonPath('errors.quantity.0', 'Only 2 more of Scarce Part can be added (2 in stock).');

    addToCart($user, $product, 2)->assertCreated();
    addToCart($user, $product, 1)->assertUnprocessable()
        ->assertJsonPath('errors.quantity.0', 'You already have all 2 available of Scarce Part in your cart.');
});

it('does not let an out-of-stock product be added', function () {
    addToCart(shopper(), Product::factory()->outOfStock()->create(['name' => 'Sold Out']), 1)->assertUnprocessable()
        ->assertJsonPath('errors.quantity.0', 'Sold Out is out of stock.');
});

it('rejects products that are not on sale or do not exist', function (string $state) {
    $product = match ($state) {
        'draft' => Product::factory()->draft()->create(),
        'archived' => Product::factory()->archived()->create(),
        'inactive category' => Product::factory()->for(Category::factory()->inactive())->create(),
        'deleted' => tap(Product::factory()->create())->delete(),
    };

    addToCart(shopper(), $product)->assertUnprocessable()->assertJsonValidationErrors('product_id');

    $this->actingAs(shopper())->postJson('/api/cart/products', ['product_id' => 999999])->assertUnprocessable();
    expect(CartItem::count())->toBe(0);
})->with(['draft', 'archived', 'inactive category', 'deleted']);

it('validates the add request', function (array $payload, string $field) {
    $this->actingAs(shopper())->postJson('/api/cart/products', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'no product' => [[], 'product_id'],
    'text product id' => [['product_id' => 'abc'], 'product_id'],
    'zero quantity' => [['product_id' => 1, 'quantity' => 0], 'quantity'],
    'decimal quantity' => [['product_id' => 1, 'quantity' => 1.5], 'quantity'],
    'negative quantity' => [['product_id' => 1, 'quantity' => -2], 'quantity'],
]);

it('changes a quantity, within stock', function () {
    $user = shopper();
    $product = Product::factory()->stock(4)->create();
    addToCart($user, $product, 1);
    $item = CartItem::first();

    $this->actingAs($user)->putJson("/api/cart/items/{$item->id}", ['quantity' => 4])->assertOk()->assertJsonPath('data.items.0.quantity', 4);
    $this->actingAs($user)->putJson("/api/cart/items/{$item->id}", ['quantity' => 5])->assertUnprocessable()->assertJsonValidationErrors('quantity');
    $this->actingAs($user)->putJson("/api/cart/items/{$item->id}", ['quantity' => 2])->assertOk()->assertJsonPath('data.items.0.quantity', 2);
    $this->actingAs($user)->putJson("/api/cart/items/{$item->id}", ['quantity' => 0])->assertUnprocessable();
    expect($item->fresh()->quantity)->toBe(2);
});

it('removes a line and clears the cart', function () {
    $user = shopper();
    [$a, $b] = Product::factory()->count(2)->create()->all();
    addToCart($user, $a);
    addToCart($user, $b);

    $this->actingAs($user)->deleteJson('/api/cart/items/'.CartItem::first()->id)->assertOk()->assertJsonCount(1, 'data.items');
    $this->actingAs($user)->deleteJson('/api/cart')->assertOk()->assertJsonCount(0, 'data.items');
    expect(CartItem::count())->toBe(0);
});

it('keeps carts private: other customers get a 404 for your lines', function () {
    $owner = shopper();
    addToCart($owner, Product::factory()->create());
    $item = CartItem::first();
    $intruder = shopper();

    $this->actingAs($intruder)->putJson("/api/cart/items/{$item->id}", ['quantity' => 3])->assertNotFound();
    $this->actingAs($intruder)->deleteJson("/api/cart/items/{$item->id}")->assertNotFound();
    cartOf($intruder)->assertJsonCount(0, 'data.items');

    expect($item->fresh()->quantity)->toBe(1);
});

it('warns when a price changed after adding, and lets the customer accept it', function () {
    $user = shopper();
    $product = Product::factory()->priced(100000)->create(['name' => 'Moving Price']);
    addToCart($user, $product, 2);

    $product->update(['price' => Money::fromSen(120000)]);

    $response = cartOf($user)->assertOk()
        ->assertJsonPath('data.items.0.unit_price.amount', 120000) // the customer pays the CURRENT price
        ->assertJsonPath('data.subtotal.amount', 240000)
        ->assertJsonPath('data.has_price_changes', true)
        ->assertJsonPath('data.checkout_ready', true); // a price change warns, it does not block

    expect($response->json('data.items.0.issues.0'))->toMatchArray([
        'code' => 'price_changed',
        'severity' => 'warning',
        'message' => 'Moving Price: the price went up from RM 1,000.00 to RM 1,200.00 since you added it.',
    ]);

    $this->actingAs($user)->postJson('/api/cart/accept-prices')->assertOk()->assertJsonPath('data.has_price_changes', false)->assertJsonPath('data.items.0.issues', []);
});

it('flags a product that was withdrawn after it was added, and leaves it out of the total', function () {
    $user = shopper();
    $keep = Product::factory()->priced(10000)->create();
    $gone = Product::factory()->priced(50000)->create(['name' => 'Retired Part']);
    addToCart($user, $keep);
    addToCart($user, $gone);
    $gone->delete();

    $response = cartOf($user)->assertOk();

    expect($response->json('data.subtotal.amount'))->toBe(10000)
        ->and($response->json('data.checkout_ready'))->toBeFalse()
        ->and($response->json('data.items.1.name'))->toBe('Retired Part')
        ->and($response->json('data.items.1.issues.0.code'))->toBe('product_unavailable')
        ->and($response->json('data.items.1.purchasable'))->toBeFalse();
});

it('flags stock that ran out or dropped below the cart quantity', function () {
    $user = shopper();
    $product = Product::factory()->stock(10)->create(['name' => 'Popular Part']);
    addToCart($user, $product, 4);

    Inventory::where('product_id', $product->id)->update(['stock' => 2]);
    expect(cartOf($user)->json('data.items.0.issues.0.message'))->toBe('Only 2 of Popular Part available, but your cart needs 4.');

    Inventory::where('product_id', $product->id)->update(['stock' => 0]);
    $response = cartOf($user);
    expect($response->json('data.items.0.issues.0.code'))->toBe('out_of_stock')->and($response->json('data.checkout_ready'))->toBeFalse();
});

it('does not let two quick adds oversell: the second one is refused', function () {
    $user = shopper();
    $product = Product::factory()->stock(3)->create();

    addToCart($user, $product, 2)->assertCreated();
    addToCart($user, $product, 2)->assertUnprocessable();

    expect(CartItem::sum('quantity'))->toBe(2);
});

it('limits the number of lines in a cart', function () {
    $user = shopper();
    $cart = Cart::factory()->for($user)->create();
    CartItem::factory()->count(CartService::MAX_LINES)->create(['cart_id' => $cart->id]);

    addToCart($user, Product::factory()->create())->assertUnprocessable()->assertJsonValidationErrors('cart');
});

it('keeps the database to one line per product even if application code is bypassed', function () {
    $cart = Cart::factory()->create();
    $product = Product::factory()->create();
    CartItem::factory()->forProduct($product)->create(['cart_id' => $cart->id]);

    expect(fn () => CartItem::factory()->forProduct($product)->create(['cart_id' => $cart->id]))->toThrow(QueryException::class);
});

it('does not run extra queries per cart line (no N+1)', function () {
    $user = shopper();
    foreach (Product::factory()->count(2)->create() as $product) {
        addToCart($user, $product);
    }

    DB::enableQueryLog();
    cartOf($user)->assertOk();
    $withTwo = count(DB::getQueryLog());

    foreach (Product::factory()->count(6)->create() as $product) {
        addToCart($user, $product);
    }

    DB::flushQueryLog();
    cartOf($user)->assertOk()->assertJsonCount(8, 'data.items');

    expect(count(DB::getQueryLog()))->toBe($withTwo);
});
