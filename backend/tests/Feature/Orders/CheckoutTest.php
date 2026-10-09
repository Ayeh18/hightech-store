<?php

use App\Enums\MalaysianState;
use App\Models\Address;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSpecification;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\QueryException;

function deliveryTo(User $user, MalaysianState $state = MalaysianState::Selangor): Address
{
    return Address::factory()->for($user)->inState($state)->create();
}

function placeOrder(User $user, Address|int $address, array $extra = [], ?string $key = null)
{
    $id = $address instanceof Address ? $address->id : $address;

    return test()->actingAs($user)->postJson('/api/checkout', ['address_id' => $id, ...$extra], $key ? ['Idempotency-Key' => $key] : []);
}

function reserved(Product $product): int
{
    return Inventory::where('product_id', $product->id)->value('reserved');
}

function stockOf(Product $product): int
{
    return Inventory::where('product_id', $product->id)->value('stock');
}

it('requires login and a delivery address', function () {
    $this->postJson('/api/checkout', ['address_id' => 1])->assertUnauthorized();
    $this->postJson('/api/checkout/preview')->assertUnauthorized();

    $this->actingAs(shopper())->postJson('/api/checkout', [])->assertUnprocessable()->assertJsonValidationErrors('address_id');
});

it('places an order from the cart, priced entirely by the server', function () {
    $user = shopper();
    $gpu = Product::factory()->priced(100000)->stock(10)->create(['name' => 'Test GPU', 'sku' => 'GPU-T1']);
    addToCart($user, $gpu, 2);

    $response = placeOrder($user, deliveryTo($user))->assertCreated()
        ->assertJsonPath('data.status.value', 'PENDING_PAYMENT')
        ->assertJsonPath('data.payment_status.value', 'PENDING')
        ->assertJsonPath('data.subtotal.amount', 200000)
        ->assertJsonPath('data.discount_total.amount', 0)
        ->assertJsonPath('data.shipping_total.amount', 1500)
        ->assertJsonPath('data.total.formatted', 'RM 2,015.00')
        ->assertJsonPath('data.lines.0.kind', 'product')
        ->assertJsonPath('data.lines.0.name', 'Test GPU')
        ->assertJsonPath('data.lines.0.quantity', 2)
        ->assertJsonPath('data.can_cancel', true);

    expect($response->json('data.order_number'))->toMatch('/^ORD-\d{4}-\d{5}$/');

    $order = Order::firstOrFail();
    expect($order->user_id)->toBe($user->id)
        ->and($order->total->sen)->toBe(201500)
        ->and($order->expires_at->isFuture())->toBeTrue()
        ->and(OrderItem::where('order_id', $order->id)->count())->toBe(1);
});

it('ignores totals, prices and statuses sent by the client', function () {
    $user = shopper();
    $product = Product::factory()->priced(100000)->stock(5)->create();
    addToCart($user, $product, 1);

    placeOrder($user, deliveryTo($user), [
        'total' => 1, 'subtotal' => 1, 'shipping_total' => 0, 'discount_total' => 99999, 'status' => 'PAID', 'payment_status' => 'PAID',
        'items' => [['product_id' => $product->id, 'price' => 1, 'quantity' => 1]],
    ])->assertCreated()
        ->assertJsonPath('data.total.amount', 101500)
        ->assertJsonPath('data.status.value', 'PENDING_PAYMENT')
        ->assertJsonPath('data.payment_status.value', 'PENDING');
});

it('clears the cart and reserves stock without touching the stock count', function () {
    $user = shopper();
    $product = Product::factory()->stock(10)->create();
    addToCart($user, $product, 3);

    placeOrder($user, deliveryTo($user))->assertCreated();

    expect(reserved($product))->toBe(3)                // held for this order
        ->and(stockOf($product))->toBe(10)              // nothing is sold until it is paid
        ->and(Cart::where('user_id', $user->id)->first()->items()->count())->toBe(0);
    cartOf($user)->assertJsonPath('data.item_count', 0);
});

it('copies the delivery address, so editing the address book later cannot change the order', function () {
    $user = shopper();
    addToCart($user, Product::factory()->create());
    $address = deliveryTo($user);
    $response = placeOrder($user, $address)->assertCreated();

    $address->update(['line1' => 'A totally different street', 'city' => 'Elsewhere']);

    $this->actingAs($user)->getJson('/api/orders/'.$response->json('data.id'))
        ->assertJsonPath('data.shipping_address.line1', $response->json('data.shipping_address.line1'))
        ->assertJsonPath('data.shipping_address.city', 'Petaling Jaya');
});

it('keeps the price, name and SKU as they were when the order was placed', function () {
    $user = shopper();
    $product = Product::factory()->priced(50000)->stock(5)->create(['name' => 'Original Name', 'sku' => 'ORIG-1']);
    addToCart($user, $product);
    $order = placeOrder($user, deliveryTo($user))->assertCreated()->json('data.id');

    $product->update(['name' => 'Renamed', 'price' => Money::fromSen(99900)]);
    $product->delete();

    $this->actingAs($user)->getJson("/api/orders/{$order}")
        ->assertJsonPath('data.lines.0.name', 'Original Name')
        ->assertJsonPath('data.lines.0.sku', 'ORIG-1')
        ->assertJsonPath('data.lines.0.unit_price.amount', 50000)
        ->assertJsonPath('data.subtotal.amount', 50000);
});

it('uses the discount price when there is one', function () {
    $user = shopper();
    addToCart($user, Product::factory()->priced(100000, 80000)->create());

    placeOrder($user, deliveryTo($user))->assertCreated()->assertJsonPath('data.subtotal.amount', 80000);
});

it('orders a whole PC as one grouped line, with quantities multiplied by the number of PCs', function () {
    $user = shopper();
    $pc = compatiblePc();
    foreach ($pc as $part) {
        Inventory::where('product_id', $part->id)->update(['stock' => 10]);
    }
    addPc($user, $pc, ['name' => 'Gaming PC'])->assertCreated();
    $item = CartItem::firstOrFail();
    test()->actingAs($user)->putJson("/api/cart/items/{$item->id}", ['quantity' => 2])->assertOk();

    $response = placeOrder($user, deliveryTo($user))->assertCreated();

    expect($response->json('data.subtotal.amount'))->toBe(540000)          // 2 x RM 2,700.00
        ->and($response->json('data.lines'))->toHaveCount(1)
        ->and($response->json('data.lines.0.kind'))->toBe('build')
        ->and($response->json('data.lines.0.name'))->toBe('Gaming PC')
        ->and($response->json('data.lines.0.copies'))->toBe(2)
        ->and($response->json('data.lines.0.parts'))->toHaveCount(6)
        ->and($response->json('data.lines.0.parts.0.quantity'))->toBe(2)   // 1 CPU per PC x 2 PCs
        ->and(OrderItem::count())->toBe(6)
        // Every part is reserved for both PCs.
        ->and(reserved($pc['cpu']))->toBe(2);
});

it('adds up the order lines to exactly the subtotal and the total to exactly the parts', function () {
    $user = shopper();
    addToCart($user, Product::factory()->priced(12345)->create(), 3);
    addToCart($user, Product::factory()->priced(999)->create(), 7);

    $order = placeOrder($user, deliveryTo($user))->assertCreated();

    $lines = (int) OrderItem::sum('line_total');
    expect($lines)->toBe(12345 * 3 + 999 * 7)
        ->and($order->json('data.subtotal.amount'))->toBe($lines)
        ->and($order->json('data.total.amount'))->toBe($lines + 1500);
});

describe('shipping', function () {
    it('charges West Malaysia and East Malaysia differently', function (MalaysianState $state, int $shipping) {
        $user = shopper();
        addToCart($user, Product::factory()->priced(100000)->create());

        placeOrder($user, deliveryTo($user, $state))->assertCreated()->assertJsonPath('data.shipping_total.amount', $shipping);
    })->with([
        'Selangor' => [MalaysianState::Selangor, 1500],
        'Kuala Lumpur' => [MalaysianState::KualaLumpur, 1500],
        'Johor' => [MalaysianState::Johor, 1500],
        'Sabah' => [MalaysianState::Sabah, 3500],
        'Sarawak' => [MalaysianState::Sarawak, 3500],
        'Labuan' => [MalaysianState::Labuan, 3500],
    ]);

    it('is free from RM 3,000.00, judged after discounts', function () {
        $user = shopper();
        $product = Product::factory()->priced(320000)->stock(5)->create();
        addToCart($user, $product);
        $address = deliveryTo($user);

        // RM 3,200 qualifies for free shipping...
        $this->actingAs($user)->postJson('/api/checkout/preview', ['address_id' => $address->id])->assertJsonPath('data.shipping.amount', 0);

        // ...but with 10% off it is RM 2,880, which does not.
        Coupon::factory()->percent(1000)->create(['code' => 'TEN']);
        $this->actingAs($user)->postJson('/api/checkout/preview', ['address_id' => $address->id, 'coupon_code' => 'TEN'])
            ->assertJsonPath('data.discount.amount', 32000)
            ->assertJsonPath('data.shipping.amount', 1500)
            ->assertJsonPath('data.total.amount', 288000 + 1500);
    });
});

describe('coupons', function () {
    beforeEach(function () {
        $this->user = shopper();
        $this->product = Product::factory()->priced(200000)->stock(20)->create();
        addToCart($this->user, $this->product); // RM 2,000.00
        $this->address = deliveryTo($this->user);
    });

    it('applies a percentage coupon and records its use', function () {
        $coupon = Coupon::factory()->percent(1000)->minOrder(100000)->create(['code' => 'WELCOME10']);

        placeOrder($this->user, $this->address, ['coupon_code' => 'welcome10'])->assertCreated() // not case-sensitive
            ->assertJsonPath('data.discount_total.amount', 20000)
            ->assertJsonPath('data.total.amount', 200000 - 20000 + 1500)
            ->assertJsonPath('data.coupon_code', 'WELCOME10');

        expect(CouponUsage::where('coupon_id', $coupon->id)->where('user_id', $this->user->id)->whereNull('released_at')->count())->toBe(1);
    });

    it('rounds a percentage discount in whole sen', function () {
        Coupon::factory()->percent(333)->create(['code' => 'ODD']); // 3.33% of 200000 = 6660 exactly
        $this->actingAs($this->user)->postJson('/api/checkout/preview', ['address_id' => $this->address->id, 'coupon_code' => 'ODD'])
            ->assertJsonPath('data.discount.amount', 6660);

        Coupon::factory()->percent(1)->create(['code' => 'TINY']); // 0.01% of 200000 = 20
        $this->actingAs($this->user)->postJson('/api/checkout/preview', ['address_id' => $this->address->id, 'coupon_code' => 'TINY'])
            ->assertJsonPath('data.discount.amount', 20);
    });

    it('applies a fixed coupon, never more than the order is worth', function () {
        Coupon::factory()->fixed(5000)->create(['code' => 'SAVE50']);
        Coupon::factory()->fixed(99999999)->create(['code' => 'HUGE']);

        placeOrder($this->user, $this->address, ['coupon_code' => 'SAVE50'])->assertCreated()->assertJsonPath('data.discount_total.amount', 5000);
        addToCart($this->user, $this->product);
        placeOrder($this->user, $this->address, ['coupon_code' => 'HUGE'])->assertCreated()
            ->assertJsonPath('data.discount_total.amount', 200000)   // capped at the subtotal
            ->assertJsonPath('data.total.amount', 1500);             // only shipping is left to pay
    });

    it('caps a percentage coupon at its maximum discount', function () {
        Coupon::factory()->percent(5000)->create(['code' => 'HALF', 'max_discount_amount' => Money::fromSen(30000)]);

        placeOrder($this->user, $this->address, ['coupon_code' => 'HALF'])->assertCreated()->assertJsonPath('data.discount_total.amount', 30000);
    });

    it('refuses coupons that are not usable, with a reason the customer can act on', function (array $state, string $message) {
        Coupon::factory()->create(['code' => 'TESTCODE', ...$state]);

        placeOrder($this->user, $this->address, ['coupon_code' => 'TESTCODE'])->assertUnprocessable()->assertJsonPath('errors.coupon_code.0', $message);

        // A refused coupon must not cost the customer their cart or any stock.
        expect(Order::count())->toBe(0)->and(reserved($this->product))->toBe(0);
        cartOf($this->user)->assertJsonPath('data.item_count', 1);
    })->with([
        'inactive' => [['is_active' => false], 'That coupon code is not valid.'],
        'expired' => [['ends_at' => now()->subDay()], 'This coupon has expired.'],
        'not started' => [['starts_at' => now()->addDay()], 'This coupon is not active yet.'],
        'order too small' => [['min_order_amount' => Money::fromSen(500000)], 'Spend at least RM 5,000.00 to use TESTCODE.'],
    ]);

    it('refuses an unknown code', function () {
        placeOrder($this->user, $this->address, ['coupon_code' => 'NOPE'])->assertUnprocessable()->assertJsonPath('errors.coupon_code.0', 'That coupon code is not valid.');
    });

    it('enforces the overall usage limit', function () {
        $coupon = Coupon::factory()->create(['code' => 'ONCEONLY', 'usage_limit' => 1, 'per_user_limit' => null]);
        $other = shopper();
        $otherOrder = Order::factory()->for($other)->create();
        CouponUsage::create(['coupon_id' => $coupon->id, 'user_id' => $other->id, 'order_id' => $otherOrder->id, 'discount_amount' => Money::fromSen(100)]);

        placeOrder($this->user, $this->address, ['coupon_code' => 'ONCEONLY'])->assertUnprocessable()->assertJsonPath('errors.coupon_code.0', 'This coupon has been fully redeemed.');
    });

    it('enforces the per-customer limit', function () {
        Coupon::factory()->percent(1000)->create(['code' => 'ONEPERSON', 'per_user_limit' => 1]);

        placeOrder($this->user, $this->address, ['coupon_code' => 'ONEPERSON'])->assertCreated();
        addToCart($this->user, $this->product);
        placeOrder($this->user, $this->address, ['coupon_code' => 'ONEPERSON'])->assertUnprocessable()->assertJsonPath('errors.coupon_code.0', 'You have already used ONEPERSON.');
    });
});

describe('stock', function () {
    it('lets only one customer buy the last unit, and leaves the loser\'s cart and the stock intact', function () {
        $product = Product::factory()->priced(100000)->stock(3)->create(['name' => 'Last Ones']);
        [$alice, $bob] = [shopper(), shopper()];
        addToCart($alice, $product, 2);
        addToCart($bob, $product, 2); // both carts look fine: the cart does not hold stock

        placeOrder($alice, deliveryTo($alice))->assertCreated();
        placeOrder($bob, deliveryTo($bob))->assertUnprocessable()->assertJsonPath('errors.cart.0', 'Only 1 of Last Ones available, but your cart needs 2.');

        expect(reserved($product))->toBe(2)                         // never more than the stock
            ->and(Order::count())->toBe(1)
            ->and(stockOf($product))->toBe(3);
        cartOf($bob)->assertJsonPath('data.item_count', 2);        // Bob keeps his cart
    });

    it('refuses an order when a product is already out of stock', function () {
        $user = shopper();
        $product = Product::factory()->stock(5)->create(['name' => 'Vanishing']);
        addToCart($user, $product, 1);
        Inventory::where('product_id', $product->id)->update(['stock' => 0]);

        placeOrder($user, deliveryTo($user))->assertUnprocessable()->assertJsonPath('errors.cart.0', 'Vanishing is out of stock.');
        expect(Order::count())->toBe(0);
    });

    it('is all or nothing: one short product reserves nothing and consumes no order number', function () {
        $user = shopper();
        $plenty = Product::factory()->stock(10)->create();
        $scarce = Product::factory()->stock(5)->create();
        addToCart($user, $plenty, 2);
        addToCart($user, $scarce, 5);
        Inventory::where('product_id', $scarce->id)->update(['stock' => 4]); // sold elsewhere meanwhile

        placeOrder($user, deliveryTo($user))->assertUnprocessable();

        expect(reserved($plenty))->toBe(0)->and(reserved($scarce))->toBe(0)->and(Order::count())->toBe(0);

        // The next successful order is number 1: the failed attempt left no gap.
        $other = shopper();
        addToCart($other, $plenty, 1);
        expect(placeOrder($other, deliveryTo($other))->assertCreated()->json('data.order_number'))->toEndWith('-00001');
    });

    it('locks the inventory rows while reserving, so two customers cannot both take the last unit', function () {
        $user = shopper();
        addToCart($user, Product::factory()->create());

        DB::enableQueryLog();
        placeOrder($user, deliveryTo($user))->assertCreated();
        $locking = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'for update'));

        expect($locking->contains(fn ($q) => str_contains($q, '"inventories"')))->toBeTrue()
            ->and($locking->contains(fn ($q) => str_contains($q, '"carts"')))->toBeTrue();
    });

    it('is also protected by the database: reserved can never exceed stock', function () {
        $product = Product::factory()->stock(2)->create();

        expect(fn () => Inventory::where('product_id', $product->id)->update(['reserved' => 3]))->toThrow(QueryException::class);
    });
});

describe('blocked carts', function () {
    it('refuses an empty cart', function () {
        $user = shopper();

        placeOrder($user, deliveryTo($user))->assertUnprocessable()->assertJsonPath('errors.cart.0', 'Your cart is empty.');
    });

    it('refuses a cart with a withdrawn product and says which', function () {
        $user = shopper();
        $product = Product::factory()->create(['name' => 'Retired Thing']);
        addToCart($user, $product);
        $product->delete();

        placeOrder($user, deliveryTo($user))->assertUnprocessable()->assertJsonPath('errors.cart.0', 'Retired Thing is no longer available.');
    });

    it('refuses a PC that is no longer compatible, because the server re-checks it', function () {
        $user = shopper();
        $pc = compatiblePc();
        addPc($user, $pc)->assertCreated();
        ProductSpecification::where('product_id', $pc['motherboard']->id)->where('key', 'socket')->update(['value_string' => 'AM4']);

        placeOrder($user, deliveryTo($user))->assertUnprocessable()
            ->assertJsonPath('errors.cart.0', 'Custom PC: CPU socket AM5 is not compatible with motherboard socket AM4.');
        expect(Order::count())->toBe(0);
    });

    it('only accepts one of your own addresses', function () {
        $user = shopper();
        addToCart($user, Product::factory()->create());
        $strangers = deliveryTo(shopper());

        placeOrder($user, $strangers)->assertUnprocessable()->assertJsonPath('errors.cart.0', 'Choose a delivery address.');
        placeOrder($user, 999999)->assertUnprocessable();
        expect(Order::count())->toBe(0);
    });
});

describe('double submits', function () {
    it('returns the same order for a repeated request with the same Idempotency-Key', function () {
        $user = shopper();
        $product = Product::factory()->stock(10)->create();
        addToCart($user, $product, 2);
        $address = deliveryTo($user);

        $first = placeOrder($user, $address, [], 'checkout-key-0001')->assertCreated();
        $second = placeOrder($user, $address, [], 'checkout-key-0001')->assertOk(); // 200: already processed, not created again

        expect($second->json('data.id'))->toBe($first->json('data.id'))
            ->and($second->json('data.order_number'))->toBe($first->json('data.order_number'))
            ->and(Order::count())->toBe(1)
            ->and(reserved($product))->toBe(2); // reserved once, not twice
    });

    it('does not let one customer\'s key collide with another\'s', function () {
        [$alice, $bob] = [shopper(), shopper()];
        $product = Product::factory()->stock(10)->create();
        addToCart($alice, $product);
        addToCart($bob, $product);

        $a = placeOrder($alice, deliveryTo($alice), [], 'same-key-for-both')->assertCreated();
        $b = placeOrder($bob, deliveryTo($bob), [], 'same-key-for-both')->assertCreated();

        expect($a->json('data.id'))->not->toBe($b->json('data.id'))->and(Order::count())->toBe(2);
    });

    it('rejects a malformed key', function () {
        $user = shopper();
        addToCart($user, Product::factory()->create());

        placeOrder($user, deliveryTo($user), [], 'short')->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    });

    it('has no way to create a second order from the same cart without a key either: the cart is already empty', function () {
        $user = shopper();
        addToCart($user, Product::factory()->create());
        $address = deliveryTo($user);

        placeOrder($user, $address)->assertCreated();
        placeOrder($user, $address)->assertUnprocessable()->assertJsonPath('errors.cart.0', 'Your cart is empty.');
        expect(Order::count())->toBe(1);
    });
});

describe('preview', function () {
    it('shows the totals without creating anything', function () {
        $user = shopper();
        $product = Product::factory()->priced(100000)->stock(5)->create();
        addToCart($user, $product, 2);
        $address = deliveryTo($user, MalaysianState::Sabah);

        $this->actingAs($user)->postJson('/api/checkout/preview', ['address_id' => $address->id])->assertOk()
            ->assertJsonPath('data.subtotal.amount', 200000)
            ->assertJsonPath('data.shipping.amount', 3500)
            ->assertJsonPath('data.shipping_known', true)
            ->assertJsonPath('data.total.amount', 203500)
            ->assertJsonPath('data.can_place', true)
            ->assertJsonPath('data.blockers', [])
            ->assertJsonPath('data.payment_window_minutes', 30)
            ->assertJsonPath('data.free_shipping_over.amount', 300000);

        expect(Order::count())->toBe(0)->and(reserved($product))->toBe(0);
        cartOf($user)->assertJsonPath('data.item_count', 2);
    });

    it('does not know the shipping until an address is chosen, and says what is missing', function () {
        $user = shopper();
        addToCart($user, Product::factory()->priced(100000)->create());

        $this->actingAs($user)->postJson('/api/checkout/preview')->assertOk()
            ->assertJsonPath('data.shipping', null)
            ->assertJsonPath('data.shipping_known', false)
            ->assertJsonPath('data.total.amount', 100000)
            ->assertJsonPath('data.can_place', false)
            ->assertJsonPath('data.blockers.0', 'Choose a delivery address.');
    });

    it('shows a coupon problem as a message instead of failing the whole preview', function () {
        $user = shopper();
        addToCart($user, Product::factory()->priced(100000)->create());
        $address = deliveryTo($user);

        $this->actingAs($user)->postJson('/api/checkout/preview', ['address_id' => $address->id, 'coupon_code' => 'BOGUS'])->assertOk()
            ->assertJsonPath('data.coupon.applied', false)
            ->assertJsonPath('data.coupon.error', 'That coupon code is not valid.')
            ->assertJsonPath('data.discount.amount', 0)
            ->assertJsonPath('data.can_place', false);
    });

    it('lists what blocks an empty or broken cart', function () {
        $user = shopper();
        $address = deliveryTo($user);

        $this->actingAs($user)->postJson('/api/checkout/preview', ['address_id' => $address->id])
            ->assertJsonPath('data.blockers.0', 'Your cart is empty.')->assertJsonPath('data.can_place', false);
    });
});

it('records who placed the order in the audit log', function () {
    $user = shopper();
    addToCart($user, Product::factory()->priced(100000)->create());

    $order = placeOrder($user, deliveryTo($user))->assertCreated()->json('data');

    $log = AuditLog::where('action', 'order.created')->firstOrFail();
    expect($log->user_id)->toBe($user->id)
        ->and($log->subject_id)->toBe($order['id'])
        ->and($log->new_values)->toMatchArray(['order_number' => $order['order_number'], 'total' => 101500]);
});

it('numbers orders in sequence, one counter per year', function () {
    $first = shopper();
    $second = shopper();
    $product = Product::factory()->stock(10)->create();
    addToCart($first, $product);
    addToCart($second, $product);

    $a = placeOrder($first, deliveryTo($first))->json('data.order_number');
    $b = placeOrder($second, deliveryTo($second))->json('data.order_number');

    expect($a)->toEndWith('-00001')->and($b)->toEndWith('-00002')->and(substr($a, 0, 9))->toBe('ORD-'.now()->year.'-');
});

it('lets the database refuse an order whose total does not add up', function () {
    expect(fn () => Order::factory()->create(['total' => Money::fromSen(1)]))->toThrow(QueryException::class);
});
