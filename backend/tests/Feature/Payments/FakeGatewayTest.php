<?php

use App\Enums\OrderStatus;
use App\Jobs\DeliverFakeWebhook;
use App\Models\FakeGatewayPayment;
use App\Models\Product;
use App\Services\Payments\WebhookSigner;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

describe('the pretend payment page', function () {
    it('shows what is being paid for', function () {
        $order = unpaidOrder();
        payFor($order)->assertCreated();
        $reference = paymentOf($order)->provider_reference;

        $this->getJson("/api/fake-gateway/payments/{$reference}")->assertOk()
            ->assertJsonPath('data.reference', $reference)
            ->assertJsonPath('data.amount.amount', $order->total->sen)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.return_url', "http://frontend.test/orders/{$order->id}?payment=return");
    });

    it('does not exist for a payment it does not know, or when the simulator is off', function () {
        $order = unpaidOrder();
        payFor($order)->assertCreated();
        $reference = paymentOf($order)->provider_reference;

        $this->getJson('/api/fake-gateway/payments/fakepay_nope')->assertNotFound();

        app()['env'] = 'production';
        $this->getJson("/api/fake-gateway/payments/{$reference}")->assertNotFound();
        $this->postJson("/api/fake-gateway/payments/{$reference}/complete", ['outcome' => 'succeeded'])->assertNotFound();
    });

    it('records the customer\'s choice and queues a webhook, without telling the shop directly', function (string $outcome, string $event) {
        Queue::fake();
        $order = unpaidOrder();
        payFor($order)->assertCreated();
        $payment = paymentOf($order);

        $this->postJson("/api/fake-gateway/payments/{$payment->provider_reference}/complete", ['outcome' => $outcome])->assertOk()
            ->assertJsonPath('data.status', $outcome)
            // The way back says how it ended (a hint for the page; only the webhook can mark the order paid).
            ->assertJsonPath('data.return_url', "http://frontend.test/orders/{$order->id}?payment=return&result={$outcome}");

        expect(FakeGatewayPayment::find($payment->provider_reference)->status)->toBe($outcome);
        Queue::assertPushed(DeliverFakeWebhook::class, function (DeliverFakeWebhook $job) use ($event, $payment) {
            $body = json_decode($job->body, true);

            return $body['type'] === $event && $body['data']['reference'] === $payment->provider_reference
                && $body['data']['amount'] === $payment->amount->sen;
        });
        // The shop has not heard anything yet: the order is untouched until the webhook arrives.
        expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    })->with([
        ['succeeded', 'payment.succeeded'],
        ['failed', 'payment.failed'],
        ['cancelled', 'payment.cancelled'],
    ]);

    it('refuses an unknown outcome and a second answer', function () {
        Queue::fake();
        $order = unpaidOrder();
        payFor($order)->assertCreated();
        $url = "/api/fake-gateway/payments/{$order->payments()->first()->provider_reference}/complete";

        $this->postJson($url, ['outcome' => 'refunded'])->assertUnprocessable();
        $this->postJson($url, [])->assertUnprocessable();
        $this->postJson($url, ['outcome' => 'succeeded'])->assertOk();
        $this->postJson($url, ['outcome' => 'failed'])->assertUnprocessable()->assertJsonPath('errors.outcome.0', 'This payment has already been completed.');

        Queue::assertPushed(DeliverFakeWebhook::class, 1);
    });
});

describe('the webhook delivery job', function () {
    it('posts the event to the shop, signed so the shop accepts it', function () {
        Http::fake(['shop.test/*' => Http::response(['status' => 'processed'])]);
        $body = '{"id":"evt_1","type":"payment.succeeded"}';

        (new DeliverFakeWebhook($body))->handle(new WebhookSigner);

        Http::assertSent(function ($request) use ($body) {
            (new WebhookSigner)->verify($request->body(), $request->header('X-FakePay-Signature')[0], 'test-webhook-secret', 300);

            return $request->url() === 'http://shop.test/api/webhooks/fake' && $request->body() === $body && $request->hasHeader('Content-Type', 'application/json');
        });
    });

    it('fails when the shop does not answer with success, so the queue tries again', function () {
        Http::fake(['shop.test/*' => Http::response('boom', 500)]);

        (new DeliverFakeWebhook('{}'))->handle(new WebhookSigner);
    })->throws(RequestException::class);

    it('retries with growing pauses and keeps the same event body', function () {
        $job = new DeliverFakeWebhook('{"id":"evt_1"}');

        expect($job->tries)->toBe(5)->and($job->backoff())->toBe([5, 30, 120, 600])->and($job->body)->toBe('{"id":"evt_1"}');
    });
});

it('completes the whole journey: pay page -> queued webhook -> signed delivery -> paid order', function () {
    // The job's HTTP call is answered by the real webhook endpoint of this very app, so the signature really is checked.
    Http::fake(function ($request) {
        $response = $this->call('POST', '/api/webhooks/fake', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_FAKEPAY_SIGNATURE' => $request->header('X-FakePay-Signature')[0],
        ], $request->body());

        return Http::response($response->json(), $response->status());
    });

    $product = Product::factory()->priced(250000)->stock(3)->create();
    $order = unpaidOrder(lines: [[$product, 1]]);
    $reference = payFor($order)->assertCreated()->json('data.checkout_url');
    $reference = basename($reference);

    // Queue is "sync" in tests, so completing the page delivers the webhook straight away.
    $this->postJson("/api/fake-gateway/payments/{$reference}/complete", ['outcome' => 'succeeded'])->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and(inventoryOf($product)->only(['stock', 'reserved']))->toBe(['stock' => 2, 'reserved' => 0]);
});
