<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Address;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Notifications\OrderPaid;
use App\Notifications\PaymentFailed;
use App\Notifications\PaymentReceivedAfterCancellation;
use App\Services\Orders\StockReservation;
use App\Services\Payments\WebhookSigner;
use Database\Factories\CouponFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/** An order with a payment page already requested, ready for the gateway to report on. @return array{0: Order, 1: Payment, 2: Product} */
function orderBeingPaid(): array
{
    $product = Product::factory()->priced(100000)->stock(10)->create();
    $order = unpaidOrder(lines: [[$product, 2]]);
    payFor($order)->assertCreated();

    return [$order, paymentOf($order), $product];
}

function webhookCount(): int
{
    return DB::table('webhook_events')->count();
}

describe('forged or damaged webhooks', function () {
    it('are rejected and change nothing', function (Closure $send) {
        [$order, $payment, $product] = orderBeingPaid();
        $body = webhookBody($payment);

        $send($body)->assertUnauthorized();

        expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment)
            ->and($payment->fresh()->status)->toBe(PaymentStatus::Pending)
            ->and(webhookCount())->toBe(0)
            ->and(inventoryOf($product)->stock)->toBe(10)
            ->and(inventoryOf($product)->reserved)->toBe(2);
    })->with([
        'signed with the wrong secret' => [fn (string $body) => sendWebhook($body, secret: 'attacker-guess')],
        'body changed after signing' => [fn (string $body) => sendWebhook($body, signature: (new WebhookSigner)->sign('{"x":1}', 'test-webhook-secret', time()))],
        'timestamp too old (a replay)' => [fn (string $body) => sendWebhook($body, timestamp: time() - 400)],
        'no signature at all' => [fn (string $body) => sendWebhook($body, signature: '')],
        'a made-up signature' => [fn (string $body) => sendWebhook($body, signature: 't='.time().',v1='.str_repeat('0', 64))],
    ]);

    it('are refused when sent to a gateway we do not have', function () {
        [, $payment] = orderBeingPaid();

        sendWebhook(webhookBody($payment), provider: 'stripe')->assertNotFound();
    });

    it('are refused when the gateway is the local simulator but the app runs in production', function () {
        [, $payment] = orderBeingPaid();
        app()['env'] = 'production';

        sendWebhook(webhookBody($payment))->assertNotFound();
    });

    it('answer 400 when correctly signed but not understandable', function (string $body) {
        sendWebhook($body)->assertStatus(400);
        expect(webhookCount())->toBe(0);
    })->with([
        'not JSON' => ['hello'],
        'no id' => ['{"type":"payment.succeeded","data":{"reference":"x","amount":1}}'],
        'unknown event type' => ['{"id":"e1","type":"payment.exploded","data":{"reference":"x"}}'],
        'success without an amount' => ['{"id":"e1","type":"payment.succeeded","data":{"reference":"x"}}'],
        'amount as a decimal string' => ['{"id":"e1","type":"payment.succeeded","data":{"reference":"x","amount":"10.50"}}'],
    ]);
});

describe('a successful payment', function () {
    it('marks the payment and the order paid and turns the reserved stock into sold stock', function () {
        Notification::fake();
        [$order, $payment, $product] = orderBeingPaid();
        expect(inventoryOf($product)->only(['stock', 'reserved']))->toBe(['stock' => 10, 'reserved' => 2]);

        gatewayPays($payment)->assertOk()->assertExactJson(['status' => 'processed']);

        $order->refresh();
        expect($order->status)->toBe(OrderStatus::Paid)
            ->and($order->payment_status)->toBe(PaymentStatus::Paid)
            ->and($order->paid_at)->not->toBeNull()
            ->and($order->expires_at)->toBeNull();
        expect($payment->fresh())->status->toBe(PaymentStatus::Paid)
            ->and($payment->fresh()->paid_at)->not->toBeNull()
            ->and($payment->fresh()->method)->toBe('fakepay')
            ->and($payment->fresh()->requires_refund)->toBeFalse();

        // Sold, not just held: stock really went down, and the shelf's "available" did not change.
        $inventory = inventoryOf($product);
        expect($inventory->stock)->toBe(8)->and($inventory->reserved)->toBe(0)->and($inventory->available)->toBe(8);

        $movement = InventoryMovement::where('product_id', $product->id)->latest('id')->first();
        expect($movement->change)->toBe(-2)->and($movement->stock_after)->toBe(8)
            ->and($movement->reason)->toBe('sale')->and($movement->note)->toBe("Order {$order->order_number}");

        Notification::assertSentTo($order->user, OrderPaid::class, fn (OrderPaid $n) => $n->order->is($order));
        expect(AuditLog::where('action', 'order.paid')->where('subject_id', $order->id)->exists())->toBeTrue();
        expect(PaymentTransaction::where('payment_id', $payment->id)->pluck('type')->map->value->all())->toBe(['INITIATED', 'SUCCEEDED']);
    });

    it('sells every part of a PC order', function () {
        $user = shopper();
        $pc = compatiblePc();
        addPc($user, $pc)->assertCreated();
        $address = Address::factory()->for($user)->create();
        $id = $this->actingAs($user)->postJson('/api/checkout', ['address_id' => $address->id])->assertCreated()->json('data.id');
        $order = Order::findOrFail($id);
        payFor($order)->assertCreated();

        gatewayPays(paymentOf($order))->assertOk();

        foreach ($pc as $product) {
            expect(inventoryOf($product)->only(['stock', 'reserved']))->toBe(['stock' => 19, 'reserved' => 0]);
        }
        expect(InventoryMovement::where('reason', 'sale')->count())->toBe(count($pc));
    });

    it('keeps the coupon used, since the order is now real', function () {
        $coupon = Coupon::factory()->create(['code' => 'SAVE50']);
        $order = unpaidOrder(extra: ['coupon_code' => 'SAVE50']);
        payFor($order)->assertCreated();

        gatewayPays(paymentOf($order))->assertOk();

        expect(CouponUsage::where('order_id', $order->id)->sole()->released_at)->toBeNull();
    })->skip(fn () => ! class_exists(CouponFactory::class), 'needs a coupon factory');

    it('is applied only once when the very same event is delivered again', function () {
        Notification::fake();
        [$order, $payment, $product] = orderBeingPaid();
        $body = webhookBody($payment, eventId: 'evt_same');

        sendWebhook($body)->assertOk()->assertJsonPath('status', 'processed');
        sendWebhook($body)->assertOk()->assertJsonPath('status', 'duplicate');
        sendWebhook($body)->assertOk()->assertJsonPath('status', 'duplicate');

        expect(inventoryOf($product)->stock)->toBe(8) // sold once, not three times
            ->and(webhookCount())->toBe(1)
            ->and(InventoryMovement::where('reason', 'sale')->count())->toBe(1)
            ->and(AuditLog::where('action', 'order.paid')->count())->toBe(1);
        Notification::assertSentToTimes($order->user, OrderPaid::class, 1);
    });

    it('is applied only once when the gateway sends a second event for the same payment', function () {
        [$order, $payment, $product] = orderBeingPaid();

        gatewayPays($payment, 'evt_first')->assertJsonPath('status', 'processed');
        gatewayPays($payment, 'evt_second')->assertJsonPath('status', 'ignored');

        expect(inventoryOf($product)->stock)->toBe(8)
            ->and(PaymentTransaction::where('payment_id', $payment->id)->where('type', 'IGNORED')->count())->toBe(1)
            ->and($order->fresh()->status)->toBe(OrderStatus::Paid);
    });

    it('is accepted after an earlier "failed" report for the same payment while the order is still open', function () {
        [$order, $payment] = orderBeingPaid();
        sendWebhook(webhookBody($payment, 'payment.failed'))->assertOk();
        expect($payment->fresh()->status)->toBe(PaymentStatus::Failed);

        gatewayPays($payment)->assertJsonPath('status', 'processed');

        expect($order->fresh()->status)->toBe(OrderStatus::Paid)->and($payment->fresh()->status)->toBe(PaymentStatus::Paid);
    });

    it('is refused when the reported amount is not the amount we asked for', function (int $reported) {
        Notification::fake();
        [$order, $payment, $product] = orderBeingPaid();

        sendWebhook(webhookBody($payment, amount: $reported))->assertOk()->assertJsonPath('status', 'amount_mismatch');

        expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment)
            ->and($payment->fresh()->status)->toBe(PaymentStatus::Pending)
            ->and(inventoryOf($product)->only(['stock', 'reserved']))->toBe(['stock' => 10, 'reserved' => 2]);
        expect(PaymentTransaction::where('payment_id', $payment->id)->where('type', 'AMOUNT_MISMATCH')->exists())->toBeTrue();
        expect(AuditLog::where('action', 'payment.amount_mismatch')->exists())->toBeTrue();
        // No payment emails (the "order received" one from checkout is a different matter).
        Notification::assertNotSentTo($order->user, OrderPaid::class);
        Notification::assertNotSentTo($order->user, PaymentFailed::class);
    })->with([
        'less (paid RM 1)' => [100],
        'more' => [999_999_999],
        'zero' => [0],
    ]);

    it('does not undo itself if the stock cannot be sold: nothing is half done, and the gateway can retry', function () {
        [$order, $payment, $product] = orderBeingPaid();
        $body = webhookBody($payment, eventId: 'evt_retry');

        $this->mock(StockReservation::class)->shouldReceive('commit')->andThrow(new RuntimeException('database hiccup'));
        sendWebhook($body)->assertStatus(500);

        // Rolled back completely: still unpaid, the event is not recorded, so the retry is not mistaken for a duplicate.
        expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment)
            ->and($payment->fresh()->status)->toBe(PaymentStatus::Pending)
            ->and(webhookCount())->toBe(0)
            ->and(PaymentTransaction::where('payment_id', $payment->id)->count())->toBe(1); // only INITIATED

        $this->app->forgetInstance(StockReservation::class);
        sendWebhook($body)->assertOk()->assertJsonPath('status', 'processed');

        expect($order->fresh()->status)->toBe(OrderStatus::Paid)->and(inventoryOf($product)->stock)->toBe(8);
    });
});

describe('a failed or cancelled payment', function () {
    it('leaves the order open and its stock reserved so the customer can try again', function () {
        Notification::fake();
        [$order, $payment, $product] = orderBeingPaid();

        sendWebhook(webhookBody($payment, 'payment.failed', data: ['failure_reason' => 'Card declined']))->assertOk()->assertJsonPath('status', 'processed');

        $order->refresh();
        expect($order->status)->toBe(OrderStatus::PendingPayment)
            ->and($order->payment_status)->toBe(PaymentStatus::Failed)
            ->and($payment->fresh()->status)->toBe(PaymentStatus::Failed)
            ->and($payment->fresh()->failure_reason)->toBe('Card declined')
            ->and(inventoryOf($product)->only(['stock', 'reserved']))->toBe(['stock' => 10, 'reserved' => 2]);
        Notification::assertSentTo($order->user, PaymentFailed::class, fn (PaymentFailed $n) => $n->reason === 'Card declined');

        // ...and a second attempt can still succeed.
        payFor($order)->assertCreated();
        gatewayPays(paymentOf($order))->assertOk();
        expect($order->fresh()->status)->toBe(OrderStatus::Paid);
    });

    it('is simply recorded when the customer walks away from the payment page', function () {
        Notification::fake();
        [$order, $payment] = orderBeingPaid();

        sendWebhook(webhookBody($payment, 'payment.cancelled'))->assertOk();

        expect($payment->fresh()->status)->toBe(PaymentStatus::Cancelled)
            ->and($order->fresh()->status)->toBe(OrderStatus::PendingPayment)
            ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Pending);
        Notification::assertNotSentTo($order->user, OrderPaid::class);
        Notification::assertNotSentTo($order->user, PaymentFailed::class);
        payFor($order)->assertCreated();
    });

    it('cannot undo a payment that already succeeded (events can arrive out of order)', function () {
        [$order, $payment, $product] = orderBeingPaid();
        gatewayPays($payment)->assertOk();

        foreach (['payment.failed', 'payment.cancelled', 'payment.processing'] as $type) {
            sendWebhook(webhookBody($payment, $type))->assertOk()->assertJsonPath('status', 'ignored');
        }

        expect($order->fresh()->status)->toBe(OrderStatus::Paid)
            ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid)
            ->and(inventoryOf($product)->stock)->toBe(8);
    });
});

describe('a payment that is still being processed by the bank', function () {
    it('keeps the order safe from the expiry job until the result is known', function () {
        [$order, $payment, $product] = orderBeingPaid();

        sendWebhook(webhookBody($payment, 'payment.processing'))->assertOk();
        expect($order->fresh()->status)->toBe(OrderStatus::PaymentProcessing)
            ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Processing);

        $this->travel(2)->hours();
        $this->artisan('orders:expire-unpaid')->assertSuccessful();
        expect($order->fresh()->status)->toBe(OrderStatus::PaymentProcessing)
            ->and(inventoryOf($product)->reserved)->toBe(2);

        gatewayPays($payment)->assertOk();
        expect($order->fresh()->status)->toBe(OrderStatus::Paid)->and(inventoryOf($product)->stock)->toBe(8);
    });

    it('returns the order to "waiting" when the bank then says no', function () {
        [$order, $payment] = orderBeingPaid();
        sendWebhook(webhookBody($payment, 'payment.processing'))->assertOk();

        sendWebhook(webhookBody($payment, 'payment.failed'))->assertOk();

        expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment)->and($order->fresh()->payment_status)->toBe(PaymentStatus::Failed);
        payFor($order)->assertCreated();
    });
});

describe('a payment that arrives too late', function () {
    it('after the customer cancelled: is kept on record for a refund and does not touch stock', function () {
        Notification::fake();
        [$order, $payment, $product] = orderBeingPaid();
        $this->actingAs($order->user)->postJson("/api/orders/{$order->id}/cancel")->assertOk();
        expect(inventoryOf($product)->only(['stock', 'reserved']))->toBe(['stock' => 10, 'reserved' => 0]);

        gatewayPays($payment)->assertOk()->assertJsonPath('status', 'late_payment');

        expect($order->fresh()->status)->toBe(OrderStatus::Cancelled) // not revived: its stock may be sold to someone else
            ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid)
            ->and($payment->fresh()->requires_refund)->toBeTrue()
            ->and(inventoryOf($product)->only(['stock', 'reserved']))->toBe(['stock' => 10, 'reserved' => 0]);
        expect(PaymentTransaction::where('payment_id', $payment->id)->where('type', 'LATE_SUCCESS')->exists())->toBeTrue();
        expect(AuditLog::where('action', 'payment.received_late')->exists())->toBeTrue();
        Notification::assertSentTo($order->user, PaymentReceivedAfterCancellation::class);
        Notification::assertNotSentTo($order->user, OrderPaid::class);

        $this->actingAs($order->user)->getJson("/api/orders/{$order->id}")->assertJsonPath('data.refund_pending', true);
    });

    it('after the payment window ran out while the customer was on the payment page', function () {
        [$order, $payment, $product] = orderBeingPaid();

        $this->travel(31)->minutes();
        $this->artisan('orders:expire-unpaid')->assertSuccessful();
        expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);

        gatewayPays($payment)->assertJsonPath('status', 'late_payment');

        expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
            ->and($payment->fresh()->requires_refund)->toBeTrue()
            ->and(inventoryOf($product)->stock)->toBe(10);
    });

    it('for an order that another payment already paid: is flagged for a refund and never sells the stock twice', function () {
        Notification::fake();
        [$order, $payment, $product] = orderBeingPaid();
        gatewayPays($payment)->assertOk();

        $second = Payment::create([
            'order_id' => $order->id, 'provider' => 'fake', 'provider_reference' => 'fakepay_second',
            'status' => PaymentStatus::Pending, 'amount' => $order->total, 'currency' => 'MYR',
        ]);

        gatewayPays($second)->assertJsonPath('status', 'late_payment');

        expect($second->fresh()->requires_refund)->toBeTrue()
            ->and($order->fresh()->status)->toBe(OrderStatus::Paid)
            ->and(inventoryOf($product)->stock)->toBe(8);
        Notification::assertSentToTimes($order->user, OrderPaid::class, 1);
        Notification::assertNotSentTo($order->user, PaymentReceivedAfterCancellation::class);
    });
});

it('answers 200 for a payment it does not know, so the gateway stops asking', function () {
    [, $payment] = orderBeingPaid();
    $payment->provider_reference = 'fakepay_does_not_exist';

    sendWebhook(webhookBody($payment))->assertOk()->assertJsonPath('status', 'ignored');
});

it('needs no login and no cookie, because the signature is the proof', function () {
    [, $payment] = orderBeingPaid();
    auth()->forgetGuards();

    gatewayPays($payment)->assertOk();
});

it('records every accepted event once, with its outcome', function () {
    [, $payment] = orderBeingPaid();
    gatewayPays($payment, 'evt_a');
    gatewayPays($payment, 'evt_b');

    expect(DB::table('webhook_events')->orderBy('id')->get(['provider', 'event_id', 'type', 'source', 'outcome'])->map(fn ($r) => (array) $r)->all())
        ->toBe([
            ['provider' => 'fake', 'event_id' => 'evt_a', 'type' => 'payment.succeeded', 'source' => 'webhook', 'outcome' => 'processed'],
            ['provider' => 'fake', 'event_id' => 'evt_b', 'type' => 'payment.succeeded', 'source' => 'webhook', 'outcome' => 'ignored'],
        ]);
});
