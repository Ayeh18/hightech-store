<?php

use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Notifications\OrderShipped;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

describe('who may manage orders', function () {
    it('lets staff and admins in, and keeps customers and visitors out', function () {
        $order = paidOrder();

        $this->getJson('/api/admin/orders')->assertUnauthorized();
        $this->actingAs(shopper())->getJson('/api/admin/orders')->assertForbidden();
        $this->actingAs(shopper())->postJson("/api/admin/orders/{$order->id}/status", ['status' => 'PROCESSING'])->assertForbidden();
        $this->actingAs(withRole('staff'))->getJson('/api/admin/orders')->assertOk();
        $this->actingAs(withRole('admin'))->getJson('/api/admin/orders')->assertOk();
        expect($order->fresh()->status)->toBe(OrderStatus::Paid);
    });
});

describe('the order list', function () {
    it('shows every customer\'s orders, newest first, with who they belong to', function () {
        $first = paidOrder();
        $second = unpaidOrder();

        $response = $this->actingAs(withRole('staff'))->getJson('/api/admin/orders')->assertOk()->assertJsonCount(2, 'data');

        expect($response->json('data.0.id'))->toBe($second->id)
            ->and($response->json('data.1.customer.email'))->toBe($first->user->email)
            ->and($response->json('data.1.status.value'))->toBe('PAID')
            ->and($response->json('data.1.items_count'))->toBe(1)
            ->and($response->json('meta.total'))->toBe(2);
    });

    it('filters by status and payment status', function () {
        $paid = paidOrder();
        unpaidOrder();
        $staff = withRole('staff');

        $this->actingAs($staff)->getJson('/api/admin/orders?status=PAID')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $paid->id);
        $this->actingAs($staff)->getJson('/api/admin/orders?payment_status=PENDING')->assertJsonCount(1, 'data');
        $this->actingAs($staff)->getJson('/api/admin/orders?status=NONSENSE')->assertUnprocessable();
    });

    it('searches by order number, customer email or name (and treats % as plain text)', function () {
        $siti = User::factory()->create(['name' => 'Siti Aminah', 'email' => 'siti@example.test']);
        $siti->assignRole('customer');
        $mine = paidOrder($siti);
        paidOrder();
        $staff = withRole('staff');

        foreach ([$mine->order_number, 'siti@example', 'aminah'] as $term) {
            $this->actingAs($staff)->getJson('/api/admin/orders?q='.urlencode($term))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        }
        $this->actingAs($staff)->getJson('/api/admin/orders?q=%25')->assertJsonCount(0, 'data');
    });

    it('does not run more queries for more orders (no N+1)', function () {
        paidOrder();
        $staff = withRole('staff');
        $this->actingAs($staff)->getJson('/api/admin/orders')->assertOk(); // warm-up: the first request does one-off work

        DB::enableQueryLog();
        $this->actingAs($staff)->getJson('/api/admin/orders')->assertOk();
        $withOne = count(DB::getQueryLog());

        foreach (range(1, 4) as $_) {
            paidOrder();
        }
        DB::flushQueryLog();
        $this->actingAs($staff)->getJson('/api/admin/orders')->assertOk()->assertJsonCount(5, 'data');

        expect(count(DB::getQueryLog()))->toBe($withOne);
    });

    it('shows one order in full, with the customer and every payment', function () {
        $order = paidOrder();

        $this->actingAs(withRole('staff'))->getJson("/api/admin/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.customer.email', $order->user->email)
            ->assertJsonPath('data.payments.0.status.value', 'PAID')
            ->assertJsonPath('data.payments.0.refundable.amount', $order->total->sen)
            ->assertJsonStructure(['data' => ['lines', 'timeline', 'shipment', 'refunds', 'shipping_address']]);
    });

    it('keeps the payment company\'s own reference for people who may view payments', function () {
        $order = paidOrder();

        $this->actingAs(withRole('staff'))->getJson("/api/admin/orders/{$order->id}")->assertJsonMissingPath('data.payments.0.provider_reference');
        $this->actingAs(withRole('admin'))->getJson("/api/admin/orders/{$order->id}")->assertJsonPath('data.payments.0.provider_reference', paymentOf($order)->provider_reference);
    });
});

describe('getting an order to the customer', function () {
    it('moves a paid order through processing, packed, shipped and delivered, one step at a time', function () {
        Notification::fake();
        $order = paidOrder();
        $staff = withRole('staff');

        $this->actingAs($staff)->postJson("/api/admin/orders/{$order->id}/status", ['status' => 'PROCESSING'])->assertOk()->assertJsonPath('data.status.value', 'PROCESSING');
        $this->actingAs($staff)->postJson("/api/admin/orders/{$order->id}/status", ['status' => 'PACKED'])->assertOk()->assertJsonPath('data.status.value', 'PACKED');
        $this->actingAs($staff)->postJson("/api/admin/orders/{$order->id}/shipment", ['carrier' => 'J&T Express', 'tracking_number' => 'JT123MY'])->assertOk()
            ->assertJsonPath('data.status.value', 'SHIPPED')
            ->assertJsonPath('data.shipment.carrier', 'J&T Express')
            ->assertJsonPath('data.shipment.tracking_number', 'JT123MY');
        $this->actingAs($staff)->postJson("/api/admin/orders/{$order->id}/shipment/delivered")->assertOk()->assertJsonPath('data.status.value', 'DELIVERED')
            ->assertJsonPath('data.shipment.delivered_at', fn ($at) => $at !== null);

        $order->refresh();
        expect($order->status)->toBe(OrderStatus::Delivered)->and($order->shipment->shipped_by)->toBe($staff->id);
        expect(AuditLog::where('action', 'order.status_changed')->where('subject_id', $order->id)->count())->toBe(4);
    });

    it('tells the customer when it ships, with the tracking number', function () {
        Notification::fake();
        $order = fulfil(paidOrder(), 'packed');

        $this->actingAs(withRole('staff'))->postJson("/api/admin/orders/{$order->id}/shipment", ['carrier' => 'Pos Laju', 'tracking_number' => 'EP123'])->assertOk();

        Notification::assertSentTo($order->user, OrderShipped::class, fn (OrderShipped $n) => $n->shipment->tracking_number === 'EP123' && $n->shipment->carrier === 'Pos Laju');
    });

    it('does not allow skipping a step or going backwards', function (string $path, string $from, array $body) {
        $order = paidOrder();
        if ($from !== 'paid') {
            $order = fulfil($order, $from);
        }

        $this->actingAs(withRole('staff'))->postJson("/api/admin/orders/{$order->id}/{$path}", $body)->assertUnprocessable()->assertJsonValidationErrors('order');
        expect($order->fresh()->status)->toBe($order->status);
    })->with([
        'pack before processing' => ['status', 'paid', ['status' => 'PACKED']],
        'process twice' => ['status', 'processing', ['status' => 'PROCESSING']],
        'ship before packing' => ['shipment', 'processing', ['carrier' => 'X', 'tracking_number' => 'Y']],
        'ship twice' => ['shipment', 'shipped', ['carrier' => 'X', 'tracking_number' => 'Y']],
        'pack after shipping' => ['status', 'shipped', ['status' => 'PACKED']],
        'deliver before shipping' => ['shipment/delivered', 'packed', []],
        'deliver twice' => ['shipment/delivered', 'delivered', []],
    ]);

    it('refuses orders that are not paid, or were cancelled', function () {
        $unpaid = unpaidOrder();
        $staff = withRole('staff');

        $this->actingAs($staff)->postJson("/api/admin/orders/{$unpaid->id}/status", ['status' => 'PROCESSING'])->assertUnprocessable();

        $this->actingAs($unpaid->user)->postJson("/api/orders/{$unpaid->id}/cancel")->assertOk();
        $this->actingAs($staff)->postJson("/api/admin/orders/{$unpaid->id}/status", ['status' => 'PROCESSING'])->assertUnprocessable();
    });

    it('only accepts PROCESSING and PACKED on the status route, and needs a carrier and tracking number to ship', function () {
        $order = fulfil(paidOrder(), 'packed');
        $staff = withRole('staff');

        $this->actingAs($staff)->postJson("/api/admin/orders/{$order->id}/status", ['status' => 'SHIPPED'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->actingAs($staff)->postJson("/api/admin/orders/{$order->id}/status", ['status' => 'DELIVERED'])->assertUnprocessable();
        $this->actingAs($staff)->postJson("/api/admin/orders/{$order->id}/shipment", [])->assertUnprocessable()->assertJsonValidationErrors(['carrier', 'tracking_number']);
        expect($order->fresh()->status)->toBe(OrderStatus::Packed);
    });

    it('stops fulfilment while the customer has an open refund request', function () {
        $order = paidOrder();
        $this->actingAs($order->user)->postJson("/api/orders/{$order->id}/refund-request", ['note' => 'Changed my mind'])->assertCreated();

        $this->actingAs(withRole('staff'))->postJson("/api/admin/orders/{$order->id}/status", ['status' => 'PROCESSING'])->assertUnprocessable();
        expect($order->fresh()->status)->toBe(OrderStatus::RefundRequested);
    });
});

describe('what the customer sees', function () {
    it('shows the shipment and the whole timeline on their own order', function () {
        $order = fulfil(paidOrder(), 'shipped');

        $response = $this->actingAs($order->user)->getJson("/api/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.shipment.carrier', 'J&T Express')
            ->assertJsonPath('data.shipment.tracking_number', 'JT123456789MY');

        expect(collect($response->json('data.timeline'))->pluck('status.value')->all())
            ->toBe(['PENDING_PAYMENT', 'PAID', 'PROCESSING', 'PACKED', 'SHIPPED']);
    });

    it('hides other people\'s orders, with a 404', function () {
        $order = paidOrder();

        $this->actingAs(shopper())->getJson("/api/orders/{$order->id}")->assertNotFound();
    });
});

describe('the status history', function () {
    it('records every change, whoever or whatever caused it', function () {
        $order = paidOrder();
        $staff = withRole('staff');
        $this->actingAs($staff)->postJson("/api/admin/orders/{$order->id}/status", ['status' => 'PROCESSING'])->assertOk();

        $rows = OrderStatusHistory::where('order_id', $order->id)->orderBy('id')->get();

        expect($rows->map(fn ($r) => [$r->from_status?->value, $r->to_status->value])->all())
            ->toBe([[null, 'PENDING_PAYMENT'], ['PENDING_PAYMENT', 'PAID'], ['PAID', 'PROCESSING']])
            // the payment webhook is the system (nobody logged in); the second move was the staff member's
            ->and($rows[1]->user_id)->toBeNull()
            ->and($rows[2]->user_id)->toBe($staff->id);
    });

    it('also records a customer cancelling and the expiry job', function () {
        $cancelled = unpaidOrder();
        $this->actingAs($cancelled->user)->postJson("/api/orders/{$cancelled->id}/cancel")->assertOk();
        $expired = unpaidOrder();
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        foreach ([$cancelled, $expired] as $order) {
            expect(OrderStatusHistory::where('order_id', $order->id)->orderByDesc('id')->first()->to_status)->toBe(OrderStatus::Cancelled);
        }
    });

    it('does not write a line when nothing about the status changed', function () {
        $order = paidOrder();
        $before = OrderStatusHistory::count();

        $order->update(['notes' => 'edited']);

        expect(OrderStatusHistory::count())->toBe($before);
    });
});
