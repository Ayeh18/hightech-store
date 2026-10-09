<?php

namespace App\Services\Payments;

use App\Models\Payment;

/** The outcome of comparing one payment with the payment company's record of it. */
final readonly class ReconciliationResult
{
    /** @param  array<int, ReconciliationIssue>  $issues */
    public function __construct(public Payment $payment, public ?GatewayTransaction $gateway, public array $issues) {}

    public function matched(): bool
    {
        return $this->issues === [];
    }

    /** There is something wrong that a fresh look at the gateway can fix, and nothing wrong that needs a person. */
    public function canSync(): bool
    {
        return $this->issues !== [] && collect($this->issues)->every(fn (ReconciliationIssue $i) => $i->canSync);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payment = $this->payment;
        $order = $payment->order;

        return [
            'payment_id' => $payment->id,
            'provider' => $payment->provider,
            'provider_reference' => $payment->provider_reference,
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'total' => $order->total,
                'customer' => $order->relationLoaded('user') ? ['name' => $order->user->name, 'email' => $order->user->email] : null,
            ],
            'internal' => [
                'status' => ['value' => $payment->status->value, 'label' => $payment->status->label()],
                'amount' => $payment->amount,
                'refunded' => $payment->refunded_amount,
            ],
            'gateway' => $this->gateway === null ? null : [
                'state' => $this->gateway->state->value,
                'label' => $this->gateway->state->label(),
                'amount' => $this->gateway->amount,
                'refunded' => $this->gateway->refunded,
            ],
            'result' => $this->matched() ? 'MATCHED' : 'ERROR',
            'issues' => array_map(fn (ReconciliationIssue $i) => ['code' => $i->code, 'message' => $i->message, 'can_sync' => $i->canSync], $this->issues),
            'can_sync' => $this->canSync(),
        ];
    }
}
