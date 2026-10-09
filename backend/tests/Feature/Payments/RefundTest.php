<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Models\AuditLog;
use App\Models\FakeGatewayPayment;
use App\Models\FakeGatewayRefund;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Notifications\RefundRejected;
use App\Notifications\RefundSucceeded;
use App\Services\Payments\Exceptions\RefundRefused;
use App\Services\Payments\Gateways\FakeGateway;
use App\Services\Payments\RefundRequest;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/** A paid order for 2 units of one product: [order, product]. Stock is 10 before, 8 once paid. */
function paidTwoUnits(): array
{
    $product = Product::factory()->priced(100000)->stock(10)->create();

    return [paidOrder(lines: [[$product, 2]]), $product];
}

function askForRefund(Order $order, string $note = 'It does not fit my needs')
{
    return test()->actingAs($order->user)->postJson("/api/orders/{$order->id}/refund-request", ['note' => $note]);
}

function theRefund(Order $order): Refund
{
    return Refund::where('order_id', $order->id)->latest('created_at')->latest('id')->firstOrFail();
}

/** Makes the next refund call fail like the payment company refusing. */
function gatewayRefusesRefunds(string $why = 'FakePay: the bank said no.'): void
{
    test()->mock(FakeGateway::class, fn ($mock) => $mock->shouldReceive('refundPayment')->andThrow(new RefundRefused($why)));
}

function gatewayWorksAgain(): void
{
    app()->forgetInstance(FakeGateway::class);
}

describe('asking for a refund', function () {
    it('records the request for the full amount, pays nothing out, and tells the customer it is waiting', function () {
        [$order, $product] = paidTwoUnits();

        askForRefund($order, 'Wrong model')->assertCreated()
            ->assertJsonPath('data.status.value', 'REFUND_REQUESTED')
            ->assertJsonPath('data.can_request_refund', false)
            ->assertJsonPath('data.refunds.0.status.value', 'REQUESTED')
            ->assertJsonPath('data.refunds.0.note', 'Wrong model')
            ->assertJsonPath('data.refunds.0.amount.amount', $order->total->sen);

        expect(paymentOf($order)->status)->toBe(PaymentStatus::Paid)
            ->and(paymentOf($order)->refunded_amount->sen)->toBe(0)
            ->and(FakeGatewayRefund::count())->toBe(0)
            ->and(inventoryOf($product)->stock)->toBe(8);
        expect(AuditLog::where('action', 'refund.requested')->exists())->toBeTrue();
    });

    it('needs a reason', function () {
        $order = paidOrder();

        $this->actingAs($order->user)->postJson("/api/orders/{$order->id}/refund-request", [])->assertUnprocessable()->assertJsonValidationErrors('note');
        expect(Refund::count())->toBe(0);
    });

    it('is only for your own order', function () {
        $order = paidOrder();

        $this->postJson("/api/orders/{$order->id}/refund-request", ['note' => 'x y z'])->assertUnauthorized();
        $this->actingAs(shopper())->postJson("/api/orders/{$order->id}/refund-request", ['note' => 'x y z'])->assertNotFound();
        expect(Refund::count())->toBe(0);
    });

    it('is refused for an order that was never paid or was cancelled', function () {
        $unpaid = unpaidOrder();
        $cancelled = unpaidOrder();
        $this->actingAs($cancelled->user)->postJson("/api/orders/{$cancelled->id}/cancel")->assertOk();

        askForRefund($unpaid)->assertUnprocessable()->assertJsonValidationErrors('order');
        askForRefund($cancelled)->assertUnprocessable()->assertJsonValidationErrors('order');
        expect(Refund::count())->toBe(0);
    });

    it('can only be asked once at a time', function () {
        $order = paidOrder();
        askForRefund($order)->assertCreated();

        askForRefund($order)->assertUnprocessable()->assertJsonPath('errors.order.0', 'You have already asked for a refund on this order. We will review it soon.');
        expect(Refund::count())->toBe(1);
    });

    it('stays possible on a delivered order for 14 days, then closes', function () {
        $order = fulfil(paidOrder(), 'delivered');

        $this->actingAs($order->user)->getJson("/api/orders/{$order->id}")->assertJsonPath('data.can_request_refund', true);

        $this->travel(15)->days();
        $this->actingAs($order->user)->getJson("/api/orders/{$order->id}")->assertJsonPath('data.can_request_refund', false);
        askForRefund($order)->assertUnprocessable()->assertJsonPath('errors.order.0', 'The refund period for this order has ended.');
    });

    it('is offered to the customer exactly while it makes sense', function () {
        $order = paidOrder();
        $show = fn () => $this->actingAs($order->user)->getJson("/api/orders/{$order->id}")->json('data.can_request_refund');

        expect($show())->toBeTrue();
        fulfil($order, 'shipped');
        expect($show())->toBeTrue();
        askForRefund($order)->assertCreated();
        expect($show())->toBeFalse();
    });
});

describe('approving a refund', function () {
    it('refunds the whole payment, closes the order and puts unshipped goods back on the shelf', function () {
        Notification::fake();
        [$order, $product] = paidTwoUnits();
        askForRefund($order)->assertCreated();
        $refund = theRefund($order);

        $this->actingAs(withRole('admin'))->postJson("/api/admin/refunds/{$refund->id}/approve", ['note' => 'Sorry about that'])->assertOk()
            ->assertJsonPath('data.status.value', 'SUCCEEDED')
            ->assertJsonPath('data.review_note', 'Sorry about that');

        $order->refresh();
        $payment = paymentOf($order);
        expect($order->status)->toBe(OrderStatus::Refunded)
            ->and($order->payment_status)->toBe(PaymentStatus::Refunded)
            ->and($payment->status)->toBe(PaymentStatus::Refunded)
            ->and($payment->refunded_amount->sen)->toBe($order->total->sen);

        // The gateway was asked once, with the refund's own id as the idempotency key.
        $atGateway = FakeGatewayRefund::sole();
        expect($atGateway->idempotency_key)->toBe($refund->id)->and($atGateway->amount)->toBe($order->total->sen);

        // Never shipped, so the 2 units are back: 10 - 2 sold + 2 returned.
        expect(inventoryOf($product)->stock)->toBe(10);
        expect(InventoryMovement::where('product_id', $product->id)->where('reason', 'return')->sole()->change)->toBe(2);

        Notification::assertSentTo($order->user, RefundSucceeded::class, fn ($n) => $n->refund->amount->sen === $order->total->sen);
        expect(AuditLog::whereIn('action', ['refund.approved', 'refund.succeeded'])->count())->toBe(2);
        expect(OrderStatusHistory::where('order_id', $order->id)->pluck('to_status')->map->value->all())
            ->toBe(['PENDING_PAYMENT', 'PAID', 'REFUND_REQUESTED', 'REFUNDED']);
    });

    it('leaves the goods alone for a shipped order unless staff say they came back', function () {
        [$order, $product] = paidTwoUnits();
        fulfil($order, 'shipped');
        askForRefund($order)->assertCreated();
        $admin = withRole('admin');

        $this->actingAs($admin)->postJson('/api/admin/refunds/'.theRefund($order)->id.'/approve')->assertOk();

        expect(inventoryOf($product)->stock)->toBe(8)->and(theRefund($order)->restock)->toBeFalse();

        // ...and the other way round: staff confirm the parcel came back.
        [$second, $secondProduct] = paidTwoUnits();
        fulfil($second, 'delivered');
        askForRefund($second)->assertCreated();
        $this->actingAs($admin)->postJson('/api/admin/refunds/'.theRefund($second)->id.'/approve', ['restock' => true])->assertOk();

        expect(inventoryOf($secondProduct)->stock)->toBe(10);
    });

    it('can only be done once', function () {
        [$order] = paidTwoUnits();
        askForRefund($order)->assertCreated();
        $refund = theRefund($order);
        $admin = withRole('admin');

        $this->actingAs($admin)->postJson("/api/admin/refunds/{$refund->id}/approve")->assertOk();
        $this->actingAs($admin)->postJson("/api/admin/refunds/{$refund->id}/approve")->assertUnprocessable()->assertJsonValidationErrors('refund');

        expect(FakeGatewayRefund::count())->toBe(1)->and(paymentOf($order)->refunded_amount->sen)->toBe($order->total->sen);
    });

    it('is for admins only', function () {
        $order = paidOrder();
        askForRefund($order)->assertCreated();
        $url = '/api/admin/refunds/'.theRefund($order)->id.'/approve';

        auth()->forgetGuards(); // the helpers above logged the customer in; this request is from a visitor
        $this->postJson($url)->assertUnauthorized();
        $this->actingAs($order->user)->postJson($url)->assertForbidden();
        $this->actingAs(withRole('staff'))->postJson($url)->assertForbidden();
        expect(theRefund($order)->status)->toBe(RefundStatus::Requested);
    });

    it('when the payment company refuses: nothing changes, the refund says why, and it can be retried', function () {
        Notification::fake();
        [$order, $product] = paidTwoUnits();
        askForRefund($order)->assertCreated();
        $refund = theRefund($order);
        $admin = withRole('admin');
        gatewayRefusesRefunds('FakePay: the bank said no.');

        $this->actingAs($admin)->postJson("/api/admin/refunds/{$refund->id}/approve")->assertOk()
            ->assertJsonPath('data.status.value', 'FAILED')
            ->assertJsonPath('data.failure_reason', 'FakePay: the bank said no.');

        expect($order->fresh()->status)->toBe(OrderStatus::RefundRequested)
            ->and(paymentOf($order)->status)->toBe(PaymentStatus::Paid)
            ->and(paymentOf($order)->refunded_amount->sen)->toBe(0)
            ->and(inventoryOf($product)->stock)->toBe(8);
        Notification::assertNotSentTo($order->user, RefundSucceeded::class);

        gatewayWorksAgain();
        $this->actingAs($admin)->postJson("/api/admin/refunds/{$refund->id}/retry")->assertOk()->assertJsonPath('data.status.value', 'SUCCEEDED');

        expect($order->fresh()->status)->toBe(OrderStatus::Refunded)->and(inventoryOf($product)->stock)->toBe(10);
        Notification::assertSentToTimes($order->user, RefundSucceeded::class, 1);
    });

    it('never pays twice when the gateway did refund but its answer got lost', function () {
        [$order, $product] = paidTwoUnits();
        askForRefund($order)->assertCreated();
        $refund = theRefund($order);
        $real = app(FakeGateway::class);
        $calls = 0;
        $this->mock(FakeGateway::class, function ($mock) use ($real, &$calls) {
            $mock->shouldReceive('refundPayment')->andReturnUsing(function (RefundRequest $request) use ($real, &$calls) {
                $result = $real->refundPayment($request); // the money really went back...
                if (++$calls === 1) {
                    throw new RuntimeException('connection reset'); // ...but we never heard
                }

                return $result;
            });
        });
        $admin = withRole('admin');

        $this->actingAs($admin)->postJson("/api/admin/refunds/{$refund->id}/approve")->assertOk()->assertJsonPath('data.status.value', 'FAILED');
        $this->actingAs($admin)->postJson("/api/admin/refunds/{$refund->id}/retry")->assertOk()->assertJsonPath('data.status.value', 'SUCCEEDED');

        expect(FakeGatewayRefund::count())->toBe(1) // the same idempotency key: the gateway answered with the first refund
            ->and($calls)->toBe(2)
            ->and(paymentOf($order)->refunded_amount->sen)->toBe($order->total->sen)
            ->and(inventoryOf($product)->stock)->toBe(10);
    });

    it('cannot be retried when it did not fail', function () {
        $order = paidOrder();
        askForRefund($order)->assertCreated();

        $this->actingAs(withRole('admin'))->postJson('/api/admin/refunds/'.theRefund($order)->id.'/retry')->assertUnprocessable()->assertJsonValidationErrors('refund');
    });
});

describe('declining a refund', function () {
    it('puts the order back exactly where it was and tells the customer why', function (string $upTo, OrderStatus $expected) {
        Notification::fake();
        $order = fulfil(paidOrder(), $upTo);
        askForRefund($order)->assertCreated();
        $refund = theRefund($order);

        $this->actingAs(withRole('admin'))->postJson("/api/admin/refunds/{$refund->id}/reject", ['note' => 'It was used'])->assertOk()
            ->assertJsonPath('data.status.value', 'REJECTED')
            ->assertJsonPath('data.review_note', 'It was used');

        expect($order->fresh()->status)->toBe($expected)->and(FakeGatewayRefund::count())->toBe(0)->and(paymentOf($order)->refunded_amount->sen)->toBe(0);
        Notification::assertSentTo($order->user, RefundRejected::class, fn ($n) => $n->refund->review_note === 'It was used');

        // The customer sees the answer, and may ask again.
        $this->actingAs($order->user)->getJson("/api/orders/{$order->id}")
            ->assertJsonPath('data.refunds.0.status.value', 'REJECTED')
            ->assertJsonPath('data.can_request_refund', true);
    })->with([
        'a paid order' => ['processing', OrderStatus::Processing], // fulfil() starts at processing
        'a packed order' => ['packed', OrderStatus::Packed],
        'a shipped order' => ['shipped', OrderStatus::Shipped],
        'a delivered order' => ['delivered', OrderStatus::Delivered],
    ]);

    it('needs a reason, and cannot be approved afterwards', function () {
        $order = paidOrder();
        askForRefund($order)->assertCreated();
        $refund = theRefund($order);
        $admin = withRole('admin');

        $this->actingAs($admin)->postJson("/api/admin/refunds/{$refund->id}/reject", [])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->actingAs($admin)->postJson("/api/admin/refunds/{$refund->id}/reject", ['note' => 'Not eligible'])->assertOk();
        $this->actingAs($admin)->postJson("/api/admin/refunds/{$refund->id}/approve")->assertUnprocessable();
        $this->actingAs($admin)->postJson("/api/admin/refunds/{$refund->id}/reject", ['note' => 'Again'])->assertUnprocessable();
        expect(paymentOf($order)->status)->toBe(PaymentStatus::Paid);
    });
});

describe('cancelling a paid order', function () {
    it('refunds it in full, cancels it and puts the goods back', function (string $upTo) {
        Notification::fake();
        [$order, $product] = paidTwoUnits();
        $upTo === 'paid' || fulfil($order, $upTo);

        $this->actingAs(withRole('admin'))->postJson("/api/admin/orders/{$order->id}/cancel-and-refund", ['note' => 'Out of the box'])->assertOk()
            ->assertJsonPath('data.status.value', 'SUCCEEDED')
            ->assertJsonPath('data.reason.value', 'order_cancelled');

        $order->refresh();
        expect($order->status)->toBe(OrderStatus::Cancelled)
            ->and($order->payment_status)->toBe(PaymentStatus::Refunded)
            ->and($order->cancelled_at)->not->toBeNull()
            ->and(paymentOf($order)->status)->toBe(PaymentStatus::Refunded)
            ->and(inventoryOf($product)->stock)->toBe(10);
        Notification::assertSentTo($order->user, RefundSucceeded::class);
    })->with(['paid' => ['paid'], 'processing' => ['processing'], 'packed' => ['packed']]);

    it('is not possible once it has shipped, or before it is paid, or for staff without refund rights', function () {
        $shipped = fulfil(paidOrder(), 'shipped');
        $unpaid = unpaidOrder();
        $admin = withRole('admin');

        $this->actingAs($admin)->postJson("/api/admin/orders/{$shipped->id}/cancel-and-refund")->assertUnprocessable()->assertJsonValidationErrors('order');
        $this->actingAs($admin)->postJson("/api/admin/orders/{$unpaid->id}/cancel-and-refund")->assertUnprocessable();
        $this->actingAs(withRole('staff'))->postJson("/api/admin/orders/{$shipped->id}/cancel-and-refund")->assertForbidden();
        expect(Refund::count())->toBe(0)->and($shipped->fresh()->status)->toBe(OrderStatus::Shipped);
    });

    it('does nothing else if the payment company fails, and finishes properly on retry (stock restored once)', function () {
        [$order, $product] = paidTwoUnits();
        $admin = withRole('admin');
        gatewayRefusesRefunds();

        $failed = $this->actingAs($admin)->postJson("/api/admin/orders/{$order->id}/cancel-and-refund")->assertOk()->assertJsonPath('data.status.value', 'FAILED');

        expect($order->fresh()->status)->toBe(OrderStatus::Paid)->and(inventoryOf($product)->stock)->toBe(8);
        // A second cancel is refused while one is waiting for its retry.
        $this->actingAs($admin)->postJson("/api/admin/orders/{$order->id}/cancel-and-refund")->assertUnprocessable()->assertJsonValidationErrors('order');

        gatewayWorksAgain();
        $this->actingAs($admin)->postJson('/api/admin/refunds/'.$failed->json('data.id').'/retry')->assertOk()->assertJsonPath('data.status.value', 'SUCCEEDED');

        expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)->and(inventoryOf($product)->stock)->toBe(10)
            ->and(InventoryMovement::where('product_id', $product->id)->where('reason', 'return')->count())->toBe(1);
    });
});

describe('refunding an amount directly', function () {
    it('gives part of the money back (goodwill) and leaves the order alone', function () {
        [$order, $product] = paidTwoUnits();
        fulfil($order, 'delivered');
        $admin = withRole('admin');
        $payment = paymentOf($order);

        $this->actingAs($admin)->postJson("/api/admin/payments/{$payment->id}/refunds", ['amount' => 30000, 'reason' => 'goodwill', 'note' => 'Late delivery'])->assertOk()
            ->assertJsonPath('data.status.value', 'SUCCEEDED')
            ->assertJsonPath('data.amount.amount', 30000);

        $payment->refresh();
        expect($payment->status)->toBe(PaymentStatus::Paid)
            ->and($payment->refunded_amount->sen)->toBe(30000)
            ->and($payment->refundable()->sen)->toBe($order->total->sen - 30000)
            ->and($order->fresh()->status)->toBe(OrderStatus::Delivered)
            ->and(inventoryOf($product)->stock)->toBe(8); // money only: nothing goes back on the shelf

        // The rest, in full: now the payment is refunded, and the order says so but keeps its status.
        $this->actingAs($admin)->postJson("/api/admin/payments/{$payment->id}/refunds", ['amount' => $order->total->sen - 30000, 'reason' => 'goodwill'])->assertOk();
        expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded)
            ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Refunded)
            ->and($order->fresh()->status)->toBe(OrderStatus::Delivered);
    });

    it('never refunds more than was paid', function (int $amount) {
        $order = paidOrder();
        $payment = paymentOf($order);

        $this->actingAs(withRole('admin'))->postJson("/api/admin/payments/{$payment->id}/refunds", ['amount' => $amount, 'reason' => 'goodwill'])->assertUnprocessable();

        expect(FakeGatewayRefund::count())->toBe(0)->and($payment->fresh()->refunded_amount->sen)->toBe(0);
    })->with([
        'a sen too much' => fn () => 101500 + 1,
        'ten times too much' => fn () => 1_015_000,
        'zero' => fn () => 0,
        'negative' => fn () => -500,
    ]);

    it('counts a refund that is still being sent, so two at once cannot add up to more than was paid', function () {
        $order = paidOrder();
        $payment = paymentOf($order);
        Refund::create([
            'order_id' => $order->id, 'payment_id' => $payment->id, 'amount' => Money::fromSen(100000), 'currency' => 'MYR',
            'status' => RefundStatus::Processing, 'reason' => RefundReason::Goodwill,
        ]);

        $this->actingAs(withRole('admin'))->postJson("/api/admin/payments/{$payment->id}/refunds", ['amount' => 5000, 'reason' => 'goodwill'])
            ->assertUnprocessable()->assertJsonPath('errors.amount.0', fn ($m) => str_contains($m, 'RM 15.00'));
    });

    it('cannot refund a payment that is already fully refunded', function () {
        $order = paidOrder();
        $payment = paymentOf($order);
        $admin = withRole('admin');
        $this->actingAs($admin)->postJson("/api/admin/payments/{$payment->id}/refunds", ['amount' => 101500, 'reason' => 'goodwill'])->assertOk();

        $this->actingAs($admin)->postJson("/api/admin/payments/{$payment->id}/refunds", ['amount' => 100, 'reason' => 'goodwill'])->assertUnprocessable()->assertJsonValidationErrors('payment');
        expect(FakeGatewayRefund::count())->toBe(1);
    });

    it('leaves the payment untouched when the payment company says no', function () {
        $order = paidOrder();
        $payment = paymentOf($order);
        gatewayRefusesRefunds();

        $this->actingAs(withRole('admin'))->postJson("/api/admin/payments/{$payment->id}/refunds", ['amount' => 5000, 'reason' => 'goodwill'])->assertOk()
            ->assertJsonPath('data.status.value', 'FAILED');

        expect($payment->fresh()->refunded_amount->sen)->toBe(0)->and($payment->fresh()->status)->toBe(PaymentStatus::Paid);
    });

    it('needs an admin', function () {
        $order = paidOrder();
        $url = '/api/admin/payments/'.paymentOf($order)->id.'/refunds';

        $this->actingAs(withRole('staff'))->postJson($url, ['amount' => 100, 'reason' => 'goodwill'])->assertForbidden();
        $this->actingAs($order->user)->postJson($url, ['amount' => 100, 'reason' => 'goodwill'])->assertForbidden();
    });
});

describe('money that arrived after the order was cancelled', function () {
    function latePaymentFor(): array
    {
        $product = Product::factory()->priced(100000)->stock(10)->create();
        $order = unpaidOrder(lines: [[$product, 2]]);
        payFor($order)->assertCreated();
        test()->actingAs($order->user)->postJson("/api/orders/{$order->id}/cancel")->assertOk();
        // The customer still paid on the payment page (the company's own record), just after we had cancelled.
        FakeGatewayPayment::where('reference', paymentOf($order)->provider_reference)->update(['status' => 'succeeded']);
        auth()->forgetGuards();
        gatewayPays(paymentOf($order))->assertJsonPath('status', 'late_payment');

        return [$order->fresh(), $product];
    }

    it('shows up in the list of payments needing a refund, until it is refunded', function () {
        [$order] = latePaymentFor();
        $admin = withRole('admin');

        $this->actingAs($admin)->getJson('/api/admin/payments/needing-refund')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order.order_number', $order->order_number)
            ->assertJsonPath('data.0.order.customer.email', $order->user->email)
            ->assertJsonPath('data.0.requires_refund', true);

        $this->actingAs($admin)->postJson('/api/admin/payments/'.paymentOf($order)->id.'/refunds', ['amount' => $order->total->sen, 'reason' => 'late_payment'])->assertOk()
            ->assertJsonPath('data.status.value', 'SUCCEEDED');

        $this->actingAs($admin)->getJson('/api/admin/payments/needing-refund')->assertJsonCount(0, 'data');
    });

    it('is refunded without reopening the order or touching stock', function () {
        Notification::fake();
        [$order, $product] = latePaymentFor();

        $this->actingAs(withRole('admin'))->postJson('/api/admin/payments/'.paymentOf($order)->id.'/refunds', ['amount' => $order->total->sen, 'reason' => 'late_payment'])->assertOk();

        $payment = paymentOf($order);
        expect($payment->status)->toBe(PaymentStatus::Refunded)->and($payment->requires_refund)->toBeFalse()
            ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled)
            ->and(inventoryOf($product)->stock)->toBe(10); // it was never sold
        Notification::assertSentTo($order->user, RefundSucceeded::class);

        $this->actingAs($order->user)->getJson("/api/orders/{$order->id}")->assertJsonPath('data.refund_pending', false)->assertJsonPath('data.refunds.0.reason.value', 'late_payment');
    });

    it('must be refunded in full, and only when it really is a late payment', function () {
        [$late] = latePaymentFor();
        $normal = paidOrder();
        $admin = withRole('admin');

        $this->actingAs($admin)->postJson('/api/admin/payments/'.paymentOf($late)->id.'/refunds', ['amount' => 1000, 'reason' => 'late_payment'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($admin)->postJson('/api/admin/payments/'.paymentOf($normal)->id.'/refunds', ['amount' => $normal->total->sen, 'reason' => 'late_payment'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        expect(FakeGatewayRefund::count())->toBe(0);
    });
});

describe('the refund queue', function () {
    it('lists customer requests with their order and customer, for admins', function () {
        $order = paidOrder();
        askForRefund($order, 'Wrong colour')->assertCreated();
        paidOrder(); // another order with no request
        $admin = withRole('admin');

        $this->actingAs($admin)->getJson('/api/admin/refunds?status=REQUESTED')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.note', 'Wrong colour')
            ->assertJsonPath('data.0.order.order_number', $order->order_number)
            ->assertJsonPath('data.0.order.customer.email', $order->user->email);

        $this->actingAs($admin)->getJson('/api/admin/refunds?status=NONSENSE')->assertUnprocessable();
        $this->actingAs(withRole('staff'))->getJson('/api/admin/refunds')->assertForbidden();
        $this->actingAs($order->user)->getJson('/api/admin/refunds')->assertForbidden();
    });

    it('does not run more queries for more refunds (no N+1)', function () {
        askForRefund(paidOrder())->assertCreated();
        $admin = withRole('admin');
        $this->actingAs($admin)->getJson('/api/admin/refunds')->assertOk(); // warm-up

        DB::enableQueryLog();
        $this->actingAs($admin)->getJson('/api/admin/refunds')->assertOk();
        $withOne = count(DB::getQueryLog());

        foreach (range(1, 4) as $_) {
            askForRefund(paidOrder())->assertCreated();
        }
        DB::flushQueryLog();
        $this->actingAs($admin)->getJson('/api/admin/refunds')->assertOk()->assertJsonCount(5, 'data');

        expect(count(DB::getQueryLog()))->toBe($withOne);
    });
});

describe('what the database itself guarantees', function () {
    it('never lets more be refunded on a payment than was paid', function () {
        $payment = paymentOf(paidOrder());

        expect(fn () => DB::transaction(fn () => DB::update('update payments set refunded_amount = amount + 1 where id = ?', [$payment->id])))
            ->toThrow(QueryException::class);
    });

    it('allows only one waiting request per order', function () {
        $order = paidOrder();
        $payment = paymentOf($order);
        $row = fn () => Refund::create([
            'order_id' => $order->id, 'payment_id' => $payment->id, 'amount' => Money::fromSen(100), 'currency' => 'MYR',
            'status' => RefundStatus::Requested, 'reason' => RefundReason::CustomerRequest,
        ]);
        $row();

        expect(fn () => DB::transaction($row))->toThrow(QueryException::class);
    });

    it('refuses a refund of zero or less', function () {
        $order = paidOrder();

        expect(fn () => DB::transaction(fn () => DB::insert(
            "insert into refunds (id, order_id, payment_id, amount, currency, status, reason, restock, created_at, updated_at) values ('r1', ?, ?, 0, 'MYR', 'PROCESSING', 'goodwill', false, now(), now())",
            [$order->id, paymentOf($order)->id],
        )))->toThrow(QueryException::class);
    });
});

describe('the fake gateway refunds', function () {
    it('answers a repeated request with the first refund instead of refunding again', function () {
        $order = paidOrder();
        $gateway = app(FakeGateway::class);
        $request = new RefundRequest(paymentOf($order)->provider_reference, Money::fromSen(5000), 'same-key', 'test');

        $first = $gateway->refundPayment($request);
        $second = $gateway->refundPayment($request);

        expect($second->providerReference)->toBe($first->providerReference)->and(FakeGatewayRefund::count())->toBe(1);
    });

    it('refuses to refund more than was paid, or a payment that never completed', function () {
        $order = paidOrder();
        $gateway = app(FakeGateway::class);
        $reference = paymentOf($order)->provider_reference;
        $gateway->refundPayment(new RefundRequest($reference, Money::fromSen(100000), 'k1', 'test'));

        expect(fn () => $gateway->refundPayment(new RefundRequest($reference, Money::fromSen(2000), 'k2', 'test')))->toThrow(RefundRefused::class, 'more than was paid')
            ->and(fn () => $gateway->refundPayment(new RefundRequest('fakepay_unknown', Money::fromSen(1), 'k3', 'test')))->toThrow(RefundRefused::class, 'never completed');
    });
});

it('ignores a repeated "payment succeeded" webhook for a payment that has since been refunded', function () {
    $order = paidOrder();
    $payment = paymentOf($order);
    $this->actingAs(withRole('admin'))->postJson("/api/admin/payments/{$payment->id}/refunds", ['amount' => 101500, 'reason' => 'goodwill'])->assertOk();

    auth()->forgetGuards();
    sendWebhook(webhookBody($payment->fresh(), 'payment.succeeded', eventId: 'evt_again'))->assertOk()->assertJsonPath('status', 'ignored');

    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded);
});
