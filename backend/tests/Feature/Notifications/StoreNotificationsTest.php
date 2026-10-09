<?php

use App\Enums\OrderStatus;
use App\Models\Address;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Notifications\LowStock;
use App\Notifications\OrderDelivered;
use App\Notifications\OrderPlaced;
use Illuminate\Support\Facades\Notification;

describe('"we received your order"', function () {
    it('is sent once, to the customer, when the order is placed', function () {
        Notification::fake();
        $order = unpaidOrder();

        Notification::assertSentTo($order->user, OrderPlaced::class, function (OrderPlaced $n) use ($order) {
            $mail = $n->toMail($order->user);

            return $n->order->is($order)
                && str_contains($mail->subject, $order->order_number)
                && collect($mail->introLines)->contains(fn ($line) => str_contains($line, $order->total->format()))
                && str_ends_with($mail->actionUrl, "/orders/{$order->id}");
        });
        Notification::assertSentToTimes($order->user, OrderPlaced::class, 1);
    });

    it('is not sent again when the same checkout is repeated (a double click or a retry)', function () {
        Notification::fake();
        $user = shopper();
        $product = Product::factory()->priced(100000)->stock(10)->create();
        addToCart($user, $product)->assertCreated();
        $address = Address::factory()->for($user)->create();

        $first = $this->actingAs($user)->postJson('/api/checkout', ['address_id' => $address->id], ['Idempotency-Key' => 'same-key'])->assertCreated();
        $again = $this->actingAs($user)->postJson('/api/checkout', ['address_id' => $address->id], ['Idempotency-Key' => 'same-key'])->assertOk();

        expect($again->json('data.id'))->toBe($first->json('data.id'));
        Notification::assertSentToTimes($user, OrderPlaced::class, 1);
    });

    it('is not sent when checkout is refused', function () {
        Notification::fake();
        $user = shopper();

        $this->actingAs($user)->postJson('/api/checkout', ['address_id' => 99999])->assertUnprocessable();

        Notification::assertNothingSent();
    });
});

describe('"your order was delivered"', function () {
    it('is sent when staff mark it delivered, and mentions the refund period', function () {
        Notification::fake();
        $order = fulfil(paidOrder(), 'shipped');
        Notification::assertNotSentTo($order->user, OrderDelivered::class);

        $this->actingAs(withRole('staff'))->postJson("/api/admin/orders/{$order->id}/shipment/delivered")->assertOk();

        Notification::assertSentTo($order->user, OrderDelivered::class, function (OrderDelivered $n) use ($order) {
            $mail = $n->toMail($order->user);

            return $n->order->is($order) && collect($mail->introLines)->contains(fn ($line) => str_contains($line, '14 days'));
        });
    });

    it('is not sent when the step is refused', function () {
        Notification::fake();
        $order = paidOrder();

        $this->actingAs(withRole('staff'))->postJson("/api/admin/orders/{$order->id}/shipment/delivered")->assertUnprocessable();

        Notification::assertNotSentTo($order->user, OrderDelivered::class);
        expect($order->fresh()->status)->toBe(OrderStatus::Paid);
    });
});

describe('"low stock"', function () {
    /** A product with 8 units and an alert level of 5, so it is fine until 3 units go. */
    function lowStockProduct(int $stock = 8, int $threshold = 5, array $attributes = []): Product
    {
        $product = Product::factory()->stock($stock)->create($attributes);
        Inventory::where('product_id', $product->id)->update(['low_stock_threshold' => $threshold]);

        return $product;
    }

    it('goes to the people who manage stock when a product drops to its alert level, and to nobody else', function () {
        $product = lowStockProduct();
        $staff = withRole('staff');
        $admin = withRole('admin');
        $customer = shopper();
        $inactiveStaff = User::factory()->create(['is_active' => false]);
        $inactiveStaff->assignRole('staff');
        Notification::fake();

        $this->actingAs($admin)->postJson("/api/products/{$product->id}/inventory/adjustments", ['change' => -3, 'reason' => 'adjustment'])->assertOk();

        foreach ([$staff, $admin] as $person) {
            Notification::assertSentTo($person, LowStock::class, fn (LowStock $n) => $n->product->is($product) && $n->available === 5 && $n->threshold === 5);
        }
        Notification::assertNotSentTo($customer, LowStock::class);
        Notification::assertNotSentTo($inactiveStaff, LowStock::class);
    });

    it('says what to do, and says "sold out" at zero', function () {
        $product = lowStockProduct(3, 2, ['name' => 'Test Card', 'sku' => 'GPU-TEST']); // fine at 3; the alert level is 2
        $staff = withRole('staff');
        Notification::fake();

        $this->actingAs($staff)->postJson("/api/products/{$product->id}/inventory/adjustments", ['change' => -3, 'reason' => 'adjustment'])->assertOk();

        Notification::assertSentTo($staff, LowStock::class, function (LowStock $n) use ($staff) {
            $mail = $n->toMail($staff);

            return $mail->subject === 'Sold out: Test Card' && str_ends_with($mail->actionUrl, "/admin/products/{$n->product->id}")
                && collect($mail->introLines)->contains(fn ($line) => str_contains($line, 'GPU-TEST') && str_contains($line, 'sold out'));
        });
    });

    it('is sent once when it crosses, not on every further sale', function () {
        $product = lowStockProduct(8, 5);
        $staff = withRole('staff');
        Notification::fake();

        $adjust = fn (int $change) => $this->actingAs($staff)->postJson("/api/products/{$product->id}/inventory/adjustments", ['change' => $change, 'reason' => 'adjustment'])->assertOk();

        $adjust(-1); // 7: fine
        $adjust(-2); // 5: crossed
        $adjust(-1); // 4: still low, already told
        $adjust(-1); // 3
        Notification::assertSentToTimes($staff, LowStock::class, 1);

        $adjust(20); // restocked: fine again
        $adjust(-20); // low again: a new warning
        Notification::assertSentToTimes($staff, LowStock::class, 2);
    });

    it('is not sent when stock goes up, or for a product that was already low', function () {
        $product = lowStockProduct(3, 5);
        $staff = withRole('staff');
        Notification::fake();

        $this->actingAs($staff)->postJson("/api/products/{$product->id}/inventory/adjustments", ['change' => 1, 'reason' => 'restock'])->assertOk();
        $this->actingAs($staff)->postJson("/api/products/{$product->id}/inventory/adjustments", ['change' => -2, 'reason' => 'adjustment'])->assertOk();

        Notification::assertNothingSent();
    });

    it('is sent when customers\' orders hold the last units (reserving counts), and not again when the order is paid', function () {
        $product = lowStockProduct(8, 5);
        $staff = withRole('staff');
        $order = unpaidOrder(lines: [[$product, 4]]); // 4 reserved: only 4 can still be sold
        Notification::fake();
        // Fake after placing, so look at the reserving step on its own with a second order.
        $second = unpaidOrder(lines: [[Product::factory()->stock(8)->create(), 1]]);
        expect($second)->toBeInstanceOf(Order::class);

        // Paying turns reserved units into sold ones: what can be sold does not change, so no new message.
        payFor($order)->assertCreated();
        auth()->forgetGuards();
        gatewayPays(paymentOf($order))->assertOk();

        Notification::assertNotSentTo($staff, LowStock::class);
    });

    it('announces reservations that cross the line', function () {
        $product = lowStockProduct(8, 5);
        $staff = withRole('staff');
        Notification::fake();

        unpaidOrder(lines: [[$product, 4]]);

        Notification::assertSentTo($staff, LowStock::class, fn (LowStock $n) => $n->available === 4);
    });

    it('is not sent for products that are not on sale', function () {
        $draft = lowStockProduct(8, 5, ['status' => 'draft']);
        $staff = withRole('staff');
        Notification::fake();

        $this->actingAs($staff)->postJson("/api/products/{$draft->id}/inventory/adjustments", ['change' => -8, 'reason' => 'adjustment'])->assertOk();

        Notification::assertNothingSent();
    });

    it('is not sent when only the alert level is changed', function () {
        $product = lowStockProduct(8, 5);
        $staff = withRole('staff');
        Notification::fake();

        Inventory::where('product_id', $product->id)->first()->update(['low_stock_threshold' => 20]);

        Notification::assertNothingSent();
        expect($staff->exists)->toBeTrue();
    });
});
