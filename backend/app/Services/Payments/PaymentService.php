<?php

namespace App\Services\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionType;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Starts a payment for an order: decides the amount (always the order's own total) and asks the gateway for a payment page. */
class PaymentService
{
    public function __construct(private readonly GatewayManager $gateways, private readonly AuditLogger $audit) {}

    /**
     * Returns the payment the customer should pay on. If one is already waiting (a second click, a second tab, coming
     * back later) it is returned again instead of creating another.
     *
     * @throws ValidationException when the order cannot be paid right now
     */
    public function start(Order $order, User $actor): Payment
    {
        $gateway = $this->gateways->default();

        $payment = DB::transaction(function () use ($order, $gateway) {
            // Same lock order as everywhere else: the order first, then its payments.
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === OrderStatus::PaymentProcessing) {
                throw ValidationException::withMessages(['order' => 'Your payment is still being processed. Please wait a moment.']);
            }

            if (! $locked->canBePaid()) {
                throw ValidationException::withMessages(['order' => $locked->isUnpaid()
                    ? 'The payment window for this order has passed.'
                    : 'This order cannot be paid.']);
            }

            $open = Payment::where('order_id', $locked->id)->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Processing->value])->lockForUpdate()->first();

            if ($open !== null) {
                if ($open->provider_reference !== null) {
                    return $open; // already has a payment page: hand out the same one
                }

                if ($open->created_at->gt(now()->subSeconds((int) config('payments.setup_grace_seconds')))) {
                    throw ValidationException::withMessages(['order' => 'Your payment is being prepared. Please try again in a moment.']);
                }

                $this->close($open, PaymentStatus::Failed, 'The payment page was never created.');
            }

            return Payment::create([
                'order_id' => $locked->id,
                'provider' => $gateway->name(),
                'status' => PaymentStatus::Pending,
                'amount' => $locked->total,
                'currency' => Money::CURRENCY,
            ])->setRelation('order', $locked);
        });

        if (! $payment->wasRecentlyCreated) {
            return $payment;
        }

        // The call to the gateway happens OUTSIDE the transaction: holding database locks while waiting on another
        // company's server would freeze every other request for this order.
        try {
            $session = $gateway->createPayment(new PaymentRequest(
                merchantReference: $payment->id,
                orderNumber: $payment->order->order_number,
                amount: $payment->amount,
                returnUrl: config('shop.frontend_url')."/orders/{$payment->order_id}?payment=return",
                expiresAt: $payment->order->expires_at,
                description: "HighTech Store order {$payment->order->order_number}",
            ));
        } catch (Throwable $e) {
            report($e);
            DB::transaction(fn () => $this->close(Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail(), PaymentStatus::Failed, 'Could not reach the payment provider.'));

            throw ValidationException::withMessages(['payment' => 'We could not reach the payment provider. Please try again in a moment.']);
        }

        DB::transaction(function () use ($payment, $session, $actor) {
            $payment->update(['provider_reference' => $session->providerReference, 'checkout_url' => $session->checkoutUrl]);
            PaymentTransaction::create([
                'payment_id' => $payment->id,
                'type' => PaymentTransactionType::Initiated,
                'status_before' => null,
                'status_after' => PaymentStatus::Pending->value,
                'amount' => $payment->amount->sen,
                'note' => "Payment page created at {$payment->provider}.",
            ]);
            $this->audit->log('payment.initiated', $actor, $payment->order, null, [
                'payment_id' => $payment->id, 'provider' => $payment->provider, 'amount' => $payment->amount->sen,
            ]);
        });

        return $payment;
    }

    /** Closes an open payment that will never complete (failed set-up). Caller holds the lock. */
    private function close(Payment $payment, PaymentStatus $status, string $reason): void
    {
        $before = $payment->status;
        $payment->fill(['status' => $status, 'failure_reason' => $reason, 'failed_at' => now()])->save();
        PaymentTransaction::create([
            'payment_id' => $payment->id,
            'type' => PaymentTransactionType::Failed,
            'status_before' => $before->value,
            'status_after' => $status->value,
            'note' => $reason,
        ]);
    }
}
