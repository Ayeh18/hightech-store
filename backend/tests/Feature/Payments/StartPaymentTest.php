<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionType;
use App\Models\AuditLog;
use App\Models\FakeGatewayPayment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Services\Orders\OrderService;
use App\Services\Payments\Gateways\FakeGateway;
use Illuminate\Database\QueryException;

it('requires login', function () {
    $order = unpaidOrder();
    auth()->forgetGuards(); // unpaidOrder() logged the customer in; this request must come from nobody

    $this->postJson("/api/orders/{$order->id}/payments")->assertUnauthorized();
});

it('creates a payment for exactly the order total, whatever the browser says', function () {
    $order = unpaidOrder();

    $response = $this->actingAs($order->user)->postJson("/api/orders/{$order->id}/payments", ['amount' => 1, 'total' => 1])
        ->assertCreated();

    $payment = Payment::firstOrFail();
    expect($payment->amount->sen)->toBe($order->total->sen)->and($payment->amount->sen)->toBeGreaterThan(1)
        ->and($payment->order_id)->toBe($order->id)
        ->and($payment->provider)->toBe('fake')
        ->and($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->provider_reference)->toStartWith('fakepay_');

    $response->assertJsonPath('data.status.value', 'PENDING')
        ->assertJsonPath('data.amount.amount', $order->total->sen)
        ->assertJsonPath('data.checkout_url', "http://frontend.test/fake-gateway/{$payment->provider_reference}");

    // The gateway was asked for the same amount, and the order was not touched.
    expect(FakeGatewayPayment::find($payment->provider_reference)->amount)->toBe($order->total->sen);
    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
});

it('writes the first line of the payment diary and an audit entry', function () {
    $order = unpaidOrder();
    payFor($order)->assertCreated();

    $payment = paymentOf($order);
    $entry = PaymentTransaction::where('payment_id', $payment->id)->sole();

    expect($entry->type)->toBe(PaymentTransactionType::Initiated)
        ->and($entry->status_after)->toBe('PENDING')
        ->and($entry->amount)->toBe($order->total->sen);
    expect(AuditLog::where('action', 'payment.initiated')->where('subject_id', $order->id)->exists())->toBeTrue();
});

it('hands out the same payment again instead of creating a second one', function () {
    $order = unpaidOrder();

    $first = payFor($order)->assertCreated()->json('data');
    $second = payFor($order)->assertOk()->json('data');

    expect($second['id'])->toBe($first['id'])->and($second['checkout_url'])->toBe($first['checkout_url']);
    expect(Payment::count())->toBe(1)->and(FakeGatewayPayment::count())->toBe(1);
});

it('hides other customers\' orders behind a 404', function () {
    $order = unpaidOrder();

    payFor($order, shopper())->assertNotFound();
    expect(Payment::count())->toBe(0);
});

it('refuses orders that cannot be paid', function (Closure $prepare, string $message) {
    $order = unpaidOrder();
    $prepare($order);

    payFor($order)->assertUnprocessable()->assertJsonPath('errors.order.0', $message);
    expect(Payment::count())->toBe(0);
})->with([
    'cancelled' => [fn (Order $o) => app(OrderService::class)->cancel($o, $o->user, 'test'), 'This order cannot be paid.'],
    'already paid' => [fn (Order $o) => $o->forceFill(['status' => OrderStatus::Paid, 'payment_status' => PaymentStatus::Paid])->save(), 'This order cannot be paid.'],
    'window passed' => [fn (Order $o) => $o->forceFill(['expires_at' => now()->subMinute()])->save(), 'The payment window for this order has passed.'],
]);

it('lets the customer try again after a failed payment', function () {
    $order = unpaidOrder();
    payFor($order)->assertCreated();
    sendWebhook(webhookBody(paymentOf($order), 'payment.failed', data: ['failure_reason' => 'Declined']))->assertOk();

    $retry = payFor($order)->assertCreated()->json('data');

    expect(Payment::count())->toBe(2)
        ->and($retry['status']['value'])->toBe('PENDING')
        ->and(Payment::where('status', 'FAILED')->count())->toBe(1);
});

it('does not start another payment while the bank is still processing one', function () {
    $order = unpaidOrder();
    payFor($order)->assertCreated();
    sendWebhook(webhookBody(paymentOf($order), 'payment.processing'))->assertOk();

    payFor($order)->assertUnprocessable()->assertJsonPath('errors.order.0', 'Your payment is still being processed. Please wait a moment.');
});

it('allows only one open payment per order, enforced by the database', function () {
    $order = unpaidOrder();
    payFor($order)->assertCreated();

    expect(fn () => Payment::create([
        'order_id' => $order->id, 'provider' => 'fake', 'status' => PaymentStatus::Pending, 'amount' => $order->total, 'currency' => 'MYR',
    ]))->toThrow(QueryException::class);
});

it('closes the waiting payment when the order is cancelled', function () {
    $order = unpaidOrder();
    payFor($order)->assertCreated();

    $this->actingAs($order->user)->postJson("/api/orders/{$order->id}/cancel")->assertOk()
        ->assertJsonPath('data.payment.status.value', 'CANCELLED')
        ->assertJsonPath('data.can_pay', false);

    expect(paymentOf($order)->status)->toBe(PaymentStatus::Cancelled);
    expect(PaymentTransaction::where('payment_id', paymentOf($order)->id)->where('type', 'CANCELLED')->exists())->toBeTrue();
});

it('marks the payment failed and says so when the gateway cannot be reached', function () {
    $order = unpaidOrder();
    $this->mock(FakeGateway::class, function ($mock) {
        $mock->shouldReceive('name')->andReturn('fake');
        $mock->shouldReceive('createPayment')->andThrow(new RuntimeException('gateway down'));
    });

    payFor($order)->assertUnprocessable()->assertJsonPath('errors.payment.0', 'We could not reach the payment provider. Please try again in a moment.');

    expect(paymentOf($order)->status)->toBe(PaymentStatus::Failed);

    // Nothing is stuck: once the gateway is back the customer can pay.
    $this->app->forgetInstance(FakeGateway::class);
    payFor($order)->assertCreated();
    expect(Payment::where('status', 'FAILED')->count())->toBe(1)->and(Payment::where('status', 'PENDING')->count())->toBe(1);
});

it('replaces a payment page that was never created, but not one that is being created right now', function () {
    $order = unpaidOrder();

    // A request that crashed between creating the payment and getting its page.
    $stuck = Payment::create(['order_id' => $order->id, 'provider' => 'fake', 'status' => PaymentStatus::Pending, 'amount' => $order->total, 'currency' => 'MYR']);

    payFor($order)->assertUnprocessable()->assertJsonPath('errors.order.0', 'Your payment is being prepared. Please try again in a moment.');

    $this->travel(3)->minutes();
    $order->forceFill(['expires_at' => now()->addMinutes(30)])->save();
    payFor($order)->assertCreated();

    expect($stuck->fresh()->status)->toBe(PaymentStatus::Failed)->and(Payment::where('status', 'PENDING')->count())->toBe(1);
});

it('shows the newest payment and whether the order can be paid on the order itself', function () {
    $order = unpaidOrder();

    $this->actingAs($order->user)->getJson("/api/orders/{$order->id}")->assertOk()
        ->assertJsonPath('data.can_pay', true)->assertJsonPath('data.payment', null)->assertJsonPath('data.refund_pending', false);

    payFor($order)->assertCreated();

    $this->actingAs($order->user)->getJson("/api/orders/{$order->id}")
        ->assertJsonPath('data.payment.status.value', 'PENDING')
        ->assertJsonStructure(['data' => ['payment' => ['id', 'checkout_url', 'amount']]]);
});
