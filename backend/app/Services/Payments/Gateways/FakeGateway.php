<?php

namespace App\Services\Payments\Gateways;

use App\Enums\GatewayEventType;
use App\Models\FakeGatewayPayment;
use App\Models\FakeGatewayRefund;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Exceptions\InvalidWebhook;
use App\Services\Payments\Exceptions\RefundRefused;
use App\Services\Payments\GatewayEvent;
use App\Services\Payments\PaymentRequest;
use App\Services\Payments\PaymentSession;
use App\Services\Payments\RefundRequest;
use App\Services\Payments\RefundResult;
use App\Services\Payments\WebhookSigner;
use App\Support\Money;
use Illuminate\Support\Str;

/**
 * Our side of the conversation with the pretend payment company ("FakePay"): it asks FakePay for a payment page and
 * checks FakePay's webhooks. FakePay itself (the page and the sending of webhooks) is App\Services\Payments\Fake\FakePay.
 */
class FakeGateway implements PaymentGateway
{
    public const SIGNATURE_HEADER = 'x-fakepay-signature';

    private const STATUS_TO_EVENT = [
        'succeeded' => GatewayEventType::PaymentSucceeded,
        'failed' => GatewayEventType::PaymentFailed,
        'cancelled' => GatewayEventType::PaymentCancelled,
    ];

    public function __construct(private readonly WebhookSigner $signer) {}

    public function name(): string
    {
        return 'fake';
    }

    public function createPayment(PaymentRequest $request): PaymentSession
    {
        $payment = FakeGatewayPayment::create([
            'reference' => 'fakepay_'.strtolower((string) Str::ulid()),
            'merchant_reference' => $request->merchantReference,
            'description' => $request->description,
            'amount' => $request->amount->sen,
            'currency' => Money::CURRENCY,
            'return_url' => $request->returnUrl,
            'status' => 'pending',
        ]);

        return new PaymentSession($payment->reference, config('shop.frontend_url').'/fake-gateway/'.$payment->reference);
    }

    public function parseWebhook(string $payload, array $headers): GatewayEvent
    {
        $this->signer->verify(
            $payload,
            $headers[self::SIGNATURE_HEADER] ?? null,
            (string) config('payments.fake.webhook_secret'),
            (int) config('payments.webhook_tolerance_seconds'),
        );

        $data = json_decode($payload, true);

        if (! is_array($data) || ! is_string($data['id'] ?? null) || ! is_array($data['data'] ?? null)) {
            throw InvalidWebhook::malformed('missing id or data');
        }

        $type = GatewayEventType::tryFrom((string) ($data['type'] ?? ''));
        $reference = $data['data']['reference'] ?? null;
        $amount = $data['data']['amount'] ?? null;

        if ($type === null || ! is_string($reference) || $reference === '') {
            throw InvalidWebhook::malformed('unknown event type or missing payment reference');
        }

        if (($amount !== null && ! is_int($amount)) || ($type === GatewayEventType::PaymentSucceeded && ! is_int($amount))) {
            throw InvalidWebhook::malformed('a successful payment must state its amount in whole sen');
        }

        return new GatewayEvent(
            id: $data['id'],
            type: $type,
            providerReference: $reference,
            amount: $amount === null ? null : Money::fromSen($amount),
            method: is_string($data['data']['method'] ?? null) ? $data['data']['method'] : null,
            failureReason: is_string($data['data']['failure_reason'] ?? null) ? $data['data']['failure_reason'] : null,
            payload: $data,
        );
    }

    public function fetchStatus(string $providerReference): ?GatewayEvent
    {
        $payment = FakeGatewayPayment::find($providerReference);
        $type = $payment ? (self::STATUS_TO_EVENT[$payment->status] ?? null) : null;

        if ($payment === null || $type === null) {
            return null; // unknown to the gateway, or still undecided
        }

        return new GatewayEvent(
            id: "status_{$payment->reference}_{$payment->status}",
            type: $type,
            providerReference: $payment->reference,
            amount: Money::fromSen($payment->amount),
            method: 'fakepay',
            failureReason: $payment->status === 'failed' ? 'Declined (simulated)' : null,
            payload: ['source' => 'status lookup', 'status' => $payment->status],
        );
    }

    public function refundPayment(RefundRequest $request): RefundResult
    {
        // A repeat of a request we already answered gets the same answer, and nothing is refunded again.
        if ($existing = FakeGatewayRefund::where('idempotency_key', $request->idempotencyKey)->first()) {
            return new RefundResult($existing->reference);
        }

        $payment = FakeGatewayPayment::find($request->paymentReference);

        if ($payment === null || $payment->status !== 'succeeded') {
            throw new RefundRefused('FakePay: that payment was never completed, so there is nothing to refund.');
        }

        $alreadyRefunded = (int) FakeGatewayRefund::where('payment_reference', $payment->reference)->sum('amount');

        if ($alreadyRefunded + $request->amount->sen > $payment->amount) {
            throw new RefundRefused('FakePay: refunds would add up to more than was paid.');
        }

        $refund = FakeGatewayRefund::create([
            'reference' => 'fakerefund_'.strtolower((string) Str::ulid()),
            'payment_reference' => $payment->reference,
            'idempotency_key' => $request->idempotencyKey,
            'amount' => $request->amount->sen,
            'status' => 'succeeded',
        ]);

        return new RefundResult($refund->reference);
    }
}
