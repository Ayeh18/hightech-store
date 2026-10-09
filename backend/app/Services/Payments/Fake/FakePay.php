<?php

namespace App\Services\Payments\Fake;

use App\Jobs\DeliverFakeWebhook;
use App\Models\FakeGatewayPayment;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The pretend payment company. A real gateway has a payment page where the customer pays, then tells the shop with a
 * signed webhook. This does the same: the customer's choice on the page is recorded here and delivered to the shop
 * as a webhook by a queued job, exactly as an outside company would (later, and possibly more than once).
 */
class FakePay
{
    public const OUTCOMES = ['succeeded', 'failed', 'cancelled'];

    /** @param  string  $outcome  succeeded | failed | cancelled */
    public function complete(FakeGatewayPayment $payment, string $outcome): FakeGatewayPayment
    {
        if ($payment->status !== 'pending') {
            throw ValidationException::withMessages(['outcome' => 'This payment has already been completed.']);
        }

        $payment->update(['status' => $outcome]);

        DeliverFakeWebhook::dispatch($this->webhookBody($payment));

        return $payment;
    }

    /** The JSON the webhook carries. Built once, so every retry of the delivery sends the very same event id. */
    public function webhookBody(FakeGatewayPayment $payment): string
    {
        return json_encode([
            'id' => 'evt_'.strtolower((string) Str::ulid()),
            'type' => match ($payment->status) {
                'succeeded' => 'payment.succeeded',
                'failed' => 'payment.failed',
                default => 'payment.cancelled',
            },
            'created' => time(),
            'data' => [
                'reference' => $payment->reference,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'method' => 'fakepay',
                'failure_reason' => $payment->status === 'failed' ? 'Declined (simulated)' : null,
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
