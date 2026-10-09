<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Address;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\OrderService;
use Illuminate\Console\Scheduling\Schedule;

/** Places a real order through the API: the given products (product => quantity) for this customer. */
function orderOf(User $user, array $lines, array $extra = []): Order
{
    foreach ($lines as [$product, $quantity]) {
        addToCart($user, $product, $quantity)->assertCreated();
    }

    $address = Address::factory()->for($user)->create();
    $id = test()->actingAs($user)->postJson('/api/checkout', ['address_id' => $address->id, ...$extra])->assertCreated()->json('data.id');

    return Order::findOrFail($id);
}

function reservedOf(Product $product): int
{
    return Inventory::where('product_id', $product->id)->value('reserved');
}

it('lists only my orders, newest first, with a short preview', function () {
    $me = shopper();
    $first = orderOf($me, [[Product::factory()->stock(5)->create(['name' => 'Alpha Part']), 1]]);
    $second = orderOf($me, [[Product::factory()->stock(5)->create(['name' => 'Beta Part']), 1], [Product::factory()->stock(5)->create(['name' => 'Gamma Part']), 1]]);
    orderOf(shopper(), [[Product::factory()->stock(5)->create(), 1]]); // someone else's
    $first->forceFill(['created_at' => now()->subDay()])->save();

    $response = $this->actingAs($me)->getJson('/api/orders')->assertOk()->assertJsonCount(2, 'data');

    expect($response->json('data.0.id'))->toBe($second->id)
        ->and($response->json('data.0.preview'))->toBe(['Beta Part', 'Gamma Part'])
        ->and($response->json('data.0.status.label'))->toBe('Waiting for payment')
        ->and($response->json('data.1.id'))->toBe($first->id)
        ->and($response->json('meta.total'))->toBe(2);
});

it('counts a PC as one thing in the order preview, however many parts it has', function () {
    $me = shopper();
    addPc($me, compatiblePc(), ['name' => 'Gaming PC'])->assertCreated();
    $address = Address::factory()->for($me)->create();
    $this->actingAs($me)->postJson('/api/checkout', ['address_id' => $address->id])->assertCreated();

    $this->actingAs($me)->getJson('/api/orders')->assertJsonPath('data.0.preview', ['Gaming PC'])->assertJsonPath('data.0.more', 0);
});

it('does not run extra queries per order in the list (no N+1)', function () {
    $me = shopper();
    orderOf($me, [[Product::factory()->stock(5)->create(), 1]]);

    DB::enableQueryLog();
    $this->actingAs($me)->getJson('/api/orders')->assertOk();
    $withOne = count(DB::getQueryLog());

    foreach (range(1, 4) as $_) {
        orderOf($me, [[Product::factory()->stock(5)->create(), 1], [Product::factory()->stock(5)->create(), 1]]);
    }

    DB::flushQueryLog();
    $this->actingAs($me)->getJson('/api/orders')->assertOk()->assertJsonCount(5, 'data');

    expect(count(DB::getQueryLog()))->toBe($withOne);
});

it('shows the full order to its owner only, and a 404 to everyone else (including staff)', function () {
    $owner = shopper();
    $order = orderOf($owner, [[Product::factory()->stock(5)->create(['name' => 'Mine']), 1]]);

    $this->actingAs($owner)->getJson("/api/orders/{$order->id}")->assertOk()->assertJsonPath('data.lines.0.name', 'Mine');
    $this->actingAs(shopper())->getJson("/api/orders/{$order->id}")->assertNotFound();

    $staff = User::factory()->create();
    $staff->assignRole('admin');
    $this->actingAs($staff)->getJson("/api/orders/{$order->id}")->assertNotFound(); // staff get their own admin view in a later phase
    $this->actingAs($owner)->getJson('/api/orders/'.str_repeat('0', 26))->assertNotFound();
});

it('requires login to see any order', function () {
    $order = Order::factory()->create();

    $this->getJson('/api/orders')->assertUnauthorized();
    $this->getJson("/api/orders/{$order->id}")->assertUnauthorized();
    $this->postJson("/api/orders/{$order->id}/cancel")->assertUnauthorized();
});

it('shows the two lifecycles separately: the order status and the payment status', function () {
    $user = shopper();
    $order = orderOf($user, [[Product::factory()->stock(5)->create(), 1]]);

    $this->actingAs($user)->getJson("/api/orders/{$order->id}")
        ->assertJsonPath('data.status', ['value' => 'PENDING_PAYMENT', 'label' => 'Waiting for payment'])
        ->assertJsonPath('data.payment_status', ['value' => 'PENDING', 'label' => 'Awaiting payment']);
});

describe('cancelling', function () {
    it('cancels an unpaid order and gives its stock back', function () {
        $user = shopper();
        $product = Product::factory()->stock(10)->create();
        $order = orderOf($user, [[$product, 3]]);
        expect(reservedOf($product))->toBe(3);

        $this->actingAs($user)->postJson("/api/orders/{$order->id}/cancel")->assertOk()
            ->assertJsonPath('data.status.value', 'CANCELLED')
            ->assertJsonPath('data.payment_status.value', 'CANCELLED')
            ->assertJsonPath('data.can_cancel', false)
            ->assertJsonPath('data.expires_at', null);

        expect(reservedOf($product))->toBe(0)
            ->and(Inventory::where('product_id', $product->id)->value('stock'))->toBe(10)
            ->and($order->fresh()->cancelled_at)->not->toBeNull();
    });

    it('releases stock only once, even if cancelled twice', function () {
        $user = shopper();
        $product = Product::factory()->stock(10)->create();
        $order = orderOf($user, [[$product, 2]]);
        $other = orderOf(shopper(), [[$product, 4]]); // someone else holds 4 of the same product

        $this->actingAs($user)->postJson("/api/orders/{$order->id}/cancel")->assertOk();
        $this->actingAs($user)->postJson("/api/orders/{$order->id}/cancel")->assertUnprocessable()->assertJsonPath('errors.order.0', 'This order can no longer be cancelled.');

        // The second cancel must not have released the other customer's 4 units.
        expect(reservedOf($product))->toBe(4)->and($other->fresh()->status)->toBe(OrderStatus::PendingPayment);
    });

    it('frees the coupon so it can be used again', function () {
        $user = shopper();
        $coupon = Coupon::factory()->percent(1000)->create(['code' => 'ONCE', 'per_user_limit' => 1]);
        $product = Product::factory()->priced(100000)->stock(10)->create();
        $order = orderOf($user, [[$product, 1]], ['coupon_code' => 'ONCE']);
        expect(CouponUsage::where('coupon_id', $coupon->id)->whereNull('released_at')->count())->toBe(1);

        $this->actingAs($user)->postJson("/api/orders/{$order->id}/cancel")->assertOk();

        expect(CouponUsage::where('coupon_id', $coupon->id)->whereNull('released_at')->count())->toBe(0);
        // ...and the customer can use it on a new order.
        $again = orderOf($user, [[$product, 1]], ['coupon_code' => 'ONCE']);
        expect($again->coupon_code)->toBe('ONCE');
    });

    it('cannot cancel someone else\'s order', function () {
        $order = orderOf(shopper(), [[Product::factory()->stock(5)->create(), 1]]);

        $this->actingAs(shopper())->postJson("/api/orders/{$order->id}/cancel")->assertNotFound();
        expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    });

    it('cannot cancel an order that is paid, being paid for, or already shipped', function (OrderStatus $status, PaymentStatus $payment) {
        $user = shopper();
        $product = Product::factory()->stock(10)->create();
        $order = orderOf($user, [[$product, 2]]);
        $order->forceFill(['status' => $status, 'payment_status' => $payment])->save();

        $this->actingAs($user)->postJson("/api/orders/{$order->id}/cancel")->assertUnprocessable();

        expect($order->fresh()->status)->toBe($status)->and(reservedOf($product))->toBe(2);
    })->with([
        'paid' => [OrderStatus::Paid, PaymentStatus::Paid],
        'payment processing' => [OrderStatus::PaymentProcessing, PaymentStatus::Processing],
        'shipped' => [OrderStatus::Shipped, PaymentStatus::Paid],
    ]);

    it('can cancel an order whose payment attempt failed', function () {
        $user = shopper();
        $order = orderOf($user, [[Product::factory()->stock(5)->create(), 1]]);
        $order->forceFill(['payment_status' => PaymentStatus::Failed])->save();

        $this->actingAs($user)->postJson("/api/orders/{$order->id}/cancel")->assertOk();
    });

    it('is recorded in the audit log', function () {
        $user = shopper();
        $order = orderOf($user, [[Product::factory()->stock(5)->create(), 1]]);

        $this->actingAs($user)->postJson("/api/orders/{$order->id}/cancel")->assertOk();

        $log = AuditLog::where('action', 'order.cancelled')->firstOrFail();
        expect($log->subject_id)->toBe($order->id)
            ->and($log->user_id)->toBe($user->id)
            ->and($log->old_values)->toBe(['status' => 'PENDING_PAYMENT'])
            ->and($log->new_values)->toMatchArray(['status' => 'CANCELLED', 'reason' => 'Cancelled by customer']);
    });
});

describe('expiry', function () {
    it('cancels unpaid orders whose payment window has passed and returns their stock', function () {
        $user = shopper();
        $product = Product::factory()->stock(10)->create();
        $late = orderOf($user, [[$product, 3]]);
        $fresh = orderOf(shopper(), [[$product, 2]]);
        $late->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->artisan('orders:expire-unpaid')->expectsOutput('Expired 1 unpaid order.')->assertSuccessful();

        expect($late->fresh()->status)->toBe(OrderStatus::Cancelled)
            ->and($late->fresh()->payment_status)->toBe(PaymentStatus::Cancelled)
            ->and($fresh->fresh()->status)->toBe(OrderStatus::PendingPayment)   // still inside its window
            ->and(reservedOf($product))->toBe(2);                               // only the late order's 3 came back
    });

    it('never touches paid orders or orders being paid right now', function () {
        $product = Product::factory()->stock(10)->create();
        $paid = orderOf(shopper(), [[$product, 1]]);
        $paid->forceFill(['status' => OrderStatus::Paid, 'payment_status' => PaymentStatus::Paid, 'expires_at' => now()->subHour()])->save();
        $processing = orderOf(shopper(), [[$product, 1]]);
        $processing->forceFill(['status' => OrderStatus::PaymentProcessing, 'payment_status' => PaymentStatus::Processing, 'expires_at' => now()->subHour()])->save();

        expect(app(OrderService::class)->expireOverdue())->toBe(0)
            ->and($paid->fresh()->status)->toBe(OrderStatus::Paid)
            ->and($processing->fresh()->status)->toBe(OrderStatus::PaymentProcessing)
            ->and(reservedOf($product))->toBe(2);
    });

    it('is safe to run again and again', function () {
        $product = Product::factory()->stock(10)->create();
        $order = orderOf(shopper(), [[$product, 2]]);
        $order->forceFill(['expires_at' => now()->subMinute()])->save();

        expect(app(OrderService::class)->expireOverdue())->toBe(1)
            ->and(app(OrderService::class)->expireOverdue())->toBe(0)
            ->and(reservedOf($product))->toBe(0);
    });

    it('is registered with the scheduler to run every minute', function () {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'orders:expire-unpaid'));

        expect($event)->not->toBeNull()->and($event->expression)->toBe('* * * * *');
    });
});
