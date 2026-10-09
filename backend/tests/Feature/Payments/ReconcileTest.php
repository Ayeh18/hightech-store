<?php

use App\Enums\GatewayEventType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\GatewayEvent;
use App\Services\Payments\Gateways\FakeGateway;
use App\Services\Payments\PaymentReconciler;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

/** The customer paid, but the gateway's webhook never reached us. */
function paidAtGatewayButWebhookLost(Order $order, string $outcome = 'succeeded'): Payment
{
    Queue::fake(); // the webhook "gets lost": the job is queued but never delivered
    $payment = paymentOf($order);
    test()->postJson("/api/fake-gateway/payments/{$payment->provider_reference}/complete", ['outcome' => $outcome])->assertOk();

    return $payment;
}

it('finds a payment whose webhook was lost and completes the order', function () {
    $order = unpaidOrder();
    payFor($order)->assertCreated();
    $payment = paidAtGatewayButWebhookLost($order);
    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);

    $this->travel(4)->minutes();
    $this->artisan('payments:reconcile')->expectsOutput('Reconciled 1 payment.')->assertSuccessful();

    expect($order->fresh()->status)->toBe(OrderStatus::Paid)->and($payment->fresh()->status)->toBe(PaymentStatus::Paid);
    expect(DB::table('webhook_events')->where('source', 'reconcile')->count())->toBe(1);
});

it('is safe to run again and again', function () {
    $order = unpaidOrder();
    payFor($order)->assertCreated();
    paidAtGatewayButWebhookLost($order);
    $this->travel(4)->minutes();

    expect(app(PaymentReconciler::class)->run())->toBe(1)
        ->and(app(PaymentReconciler::class)->run())->toBe(0);

    expect(inventoryOf($order->items->first()->product_id)->stock)->toBe(9); // sold once
});

it('leaves payments alone that are too new, or that the customer has not finished', function () {
    $order = unpaidOrder();
    payFor($order)->assertCreated();

    expect(app(PaymentReconciler::class)->run())->toBe(0); // not finished at the gateway

    paidAtGatewayButWebhookLost($order);
    expect(app(PaymentReconciler::class)->run())->toBe(0) // finished, but only just: the webhook may still be on its way
        ->and($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
});

it('flags the money for a refund when the customer paid after the order had expired and the webhook was lost', function () {
    $order = unpaidOrder();
    payFor($order)->assertCreated();
    $payment = paidAtGatewayButWebhookLost($order);

    $this->travel(31)->minutes();
    $this->artisan('orders:expire-unpaid')->assertSuccessful();
    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);

    app(PaymentReconciler::class)->run();

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->fresh()->requires_refund)->toBeTrue();
});

it('records a failure the webhook never reported, so the customer can try again', function () {
    $order = unpaidOrder();
    payFor($order)->assertCreated();
    $payment = paidAtGatewayButWebhookLost($order, 'failed');
    $this->travel(4)->minutes();

    app(PaymentReconciler::class)->run();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Failed)->and($order->fresh()->payment_status)->toBe(PaymentStatus::Failed);
});

it('keeps going when the gateway cannot answer about one payment', function () {
    $broken = unpaidOrder();
    payFor($broken)->assertCreated();
    $fine = unpaidOrder();
    payFor($fine)->assertCreated();
    $this->travel(4)->minutes();
    $brokenRef = paymentOf($broken)->provider_reference;
    $finePayment = paymentOf($fine);

    $this->mock(FakeGateway::class, function ($mock) use ($brokenRef, $finePayment) {
        $mock->shouldReceive('fetchStatus')->andReturnUsing(function (string $reference) use ($brokenRef, $finePayment) {
            if ($reference === $brokenRef) {
                throw new RuntimeException('gateway timeout');
            }

            return new GatewayEvent('status_ok', GatewayEventType::PaymentSucceeded, $reference, $finePayment->amount);
        });
    });

    expect(app(PaymentReconciler::class)->run())->toBe(1);
    expect($fine->fresh()->status)->toBe(OrderStatus::Paid)->and($broken->fresh()->status)->toBe(OrderStatus::PendingPayment);
});

it('is registered with the scheduler to run every five minutes', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'payments:reconcile'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('*/5 * * * *');
});
