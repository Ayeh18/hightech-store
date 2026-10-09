<?php

namespace App\Services\Payments;

use App\Enums\GatewayEventType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\WebhookOutcome;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Notifications\OrderPaid;
use App\Notifications\PaymentFailed;
use App\Notifications\PaymentReceivedAfterCancellation;
use App\Services\AuditLogger;
use App\Services\Orders\StockReservation;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decides what a gateway event means for a payment and its order. This is the ONLY place a payment (and so an order)
 * can become paid.
 *
 * Three guarantees, each by a different mechanism:
 *  1. The same event never counts twice: it is written to `webhook_events` (unique per provider + event id) in the same
 *     transaction as its effects. A repeat finds the row already there and does nothing.
 *  2. Events that arrive together or in the wrong order cannot corrupt anything: the order and the payment are locked
 *     (always in that order) and every move is checked against the payment's current state ("a failed event after a
 *     paid one is ignored").
 *  3. A payment is only accepted for the amount we asked for.
 */
class PaymentProcessor
{
    public function __construct(private readonly StockReservation $stock, private readonly AuditLogger $audit) {}

    /** @param  string  $source  webhook | reconcile */
    public function handle(string $provider, GatewayEvent $event, string $source = 'webhook'): WebhookOutcome
    {
        $afterCommit = [];

        $outcome = DB::transaction(function () use ($provider, $event, $source, &$afterCommit) {
            // ON CONFLICT DO NOTHING: if the same event is being handled at this very moment by another request, this
            // waits for it to finish, then reports 0 rows. Either way only one request goes on to apply the event.
            $recorded = DB::table('webhook_events')->insertOrIgnore([
                'provider' => $provider,
                'event_id' => $event->id,
                'type' => $event->type->value,
                'source' => $source,
                'payload' => json_encode($event->payload, JSON_THROW_ON_ERROR),
                'received_at' => now(),
            ]);

            if ($recorded === 0) {
                return WebhookOutcome::Duplicate;
            }

            $outcome = $this->apply($provider, $event, $afterCommit);

            DB::table('webhook_events')->where('provider', $provider)->where('event_id', $event->id)->update(['outcome' => $outcome->value]);

            return $outcome;
        });

        // Emails go out only once the money movement is safely committed. A mail problem must not turn a processed
        // payment into an error (the gateway would only retry it), so it is reported and swallowed.
        foreach ($afterCommit as $send) {
            try {
                $send();
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $outcome;
    }

    /** @param  array<int, Closure>  $afterCommit */
    private function apply(string $provider, GatewayEvent $event, array &$afterCommit): WebhookOutcome
    {
        $found = Payment::where('provider', $provider)->where('provider_reference', $event->providerReference)->first();

        if ($found === null) {
            Log::warning('Payment event for an unknown payment.', ['provider' => $provider, 'event' => $event->id, 'reference' => $event->providerReference]);

            return WebhookOutcome::Ignored;
        }

        // Always lock the order first and the payment second (the same order as cancelling an order does), so two
        // requests that need both can never wait for each other forever.
        $order = Order::whereKey($found->order_id)->lockForUpdate()->with(['items', 'user'])->firstOrFail();
        $payment = Payment::whereKey($found->id)->lockForUpdate()->firstOrFail();

        return match ($event->type) {
            GatewayEventType::PaymentSucceeded => $this->succeeded($order, $payment, $event, $afterCommit),
            GatewayEventType::PaymentFailed => $this->failed($order, $payment, $event, $afterCommit),
            GatewayEventType::PaymentCancelled => $this->cancelled($order, $payment, $event),
            GatewayEventType::PaymentProcessing => $this->processing($order, $payment, $event),
        };
    }

    /** @param  array<int, Closure>  $afterCommit */
    private function succeeded(Order $order, Payment $payment, GatewayEvent $event, array &$afterCommit): WebhookOutcome
    {
        if (in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::Refunded], true)) {
            return $this->ignore($payment, $event, 'Already paid.');
        }

        // Never take the gateway's word for how much was paid: it must be what we asked for.
        if ($event->amount === null || ! $event->amount->equals($payment->amount)) {
            $this->record($payment, PaymentTransactionType::AmountMismatch, $payment->status, $payment->status, $event,
                "Gateway reported {$event->amount?->format()}, expected {$payment->amount->format()}.");
            $this->audit->log('payment.amount_mismatch', null, $order, null, [
                'payment_id' => $payment->id, 'expected' => $payment->amount->sen, 'reported' => $event->amount?->sen,
            ]);

            return WebhookOutcome::AmountMismatch;
        }

        $before = $payment->status;
        $payment->status = PaymentStatus::Paid;
        $payment->paid_at = now();
        $payment->method = $event->method ?? $payment->method;
        $payment->failure_reason = null;

        if ($order->isUnpaid() || $order->status === OrderStatus::PaymentProcessing) {
            $payment->save();
            $this->record($payment, PaymentTransactionType::Succeeded, $before, PaymentStatus::Paid, $event);

            $orderBefore = $order->status;
            $order->moveTo(OrderStatus::Paid);
            $order->payment_status = PaymentStatus::Paid;
            $order->paid_at = now();
            $order->expires_at = null;
            $order->save();

            // The reserved units are now sold.
            $this->stock->commit($this->demand($order), $order);

            $this->audit->log('order.paid', null, $order, ['status' => $orderBefore->value], [
                'status' => OrderStatus::Paid->value, 'payment_id' => $payment->id, 'amount' => $payment->amount->sen,
            ]);
            $afterCommit[] = fn () => $order->user->notify(new OrderPaid($order));

            return WebhookOutcome::Processed;
        }

        // The money is real but the order cannot use it: it was cancelled (e.g. the payment window passed while the
        // customer was on the payment page) and its stock may already be sold to someone else, or another attempt
        // already paid it. Keep the money on record and flag it for a refund; do NOT touch stock or the order.
        $payment->requires_refund = true;
        $payment->save();
        $this->record($payment, PaymentTransactionType::LateSuccess, $before, PaymentStatus::Paid, $event,
            "Order was {$order->status->value} when the payment arrived.");
        $this->audit->log('payment.received_late', null, $order, null, [
            'payment_id' => $payment->id, 'amount' => $payment->amount->sen, 'order_status' => $order->status->value,
        ]);

        if ($order->status === OrderStatus::Cancelled) {
            $afterCommit[] = fn () => $order->user->notify(new PaymentReceivedAfterCancellation($order));
        }

        return WebhookOutcome::LatePayment;
    }

    /** @param  array<int, Closure>  $afterCommit */
    private function failed(Order $order, Payment $payment, GatewayEvent $event, array &$afterCommit): WebhookOutcome
    {
        if (! $payment->isOpen()) {
            return $this->ignore($payment, $event, "A failure arrived but the payment is already {$payment->status->value}.");
        }

        $before = $payment->status;
        $payment->fill(['status' => PaymentStatus::Failed, 'failed_at' => now(), 'failure_reason' => $event->failureReason])->save();
        $this->record($payment, PaymentTransactionType::Failed, $before, PaymentStatus::Failed, $event, $event->failureReason);

        if ($order->status === OrderStatus::PaymentProcessing) {
            $order->moveTo(OrderStatus::PendingPayment);
            $order->payment_status = PaymentStatus::Failed;
            $order->save();
        } elseif ($order->isUnpaid()) {
            $order->payment_status = PaymentStatus::Failed;
            $order->save();
        }

        $this->audit->log('payment.failed', null, $order, null, ['payment_id' => $payment->id, 'reason' => $event->failureReason]);

        if ($order->isUnpaid()) {
            $afterCommit[] = fn () => $order->user->notify(new PaymentFailed($order, $event->failureReason));
        }

        return WebhookOutcome::Processed;
    }

    private function cancelled(Order $order, Payment $payment, GatewayEvent $event): WebhookOutcome
    {
        if (! $payment->isOpen()) {
            return $this->ignore($payment, $event, "A cancellation arrived but the payment is already {$payment->status->value}.");
        }

        $before = $payment->status;
        $payment->fill(['status' => PaymentStatus::Cancelled, 'cancelled_at' => now()])->save();
        $this->record($payment, PaymentTransactionType::Cancelled, $before, PaymentStatus::Cancelled, $event, 'Cancelled at the payment page.');

        // Walking away from the payment page leaves the order exactly as it was: still waiting, still reserved.
        if ($order->status === OrderStatus::PaymentProcessing) {
            $order->moveTo(OrderStatus::PendingPayment);
            $order->payment_status = PaymentStatus::Pending;
            $order->save();
        }

        return WebhookOutcome::Processed;
    }

    private function processing(Order $order, Payment $payment, GatewayEvent $event): WebhookOutcome
    {
        if ($payment->status !== PaymentStatus::Pending) {
            return $this->ignore($payment, $event, "A processing notice arrived but the payment is already {$payment->status->value}.");
        }

        $payment->fill(['status' => PaymentStatus::Processing])->save();
        $this->record($payment, PaymentTransactionType::Processing, PaymentStatus::Pending, PaymentStatus::Processing, $event);

        // While the bank is still working, the order stops counting as "unpaid" (so the expiry job leaves it alone).
        if ($order->isUnpaid() && $order->status === OrderStatus::PendingPayment) {
            $order->moveTo(OrderStatus::PaymentProcessing);
            $order->payment_status = PaymentStatus::Processing;
            $order->save();
        }

        return WebhookOutcome::Processed;
    }

    private function ignore(Payment $payment, GatewayEvent $event, string $why): WebhookOutcome
    {
        $this->record($payment, PaymentTransactionType::Ignored, $payment->status, $payment->status, $event, $why);

        return WebhookOutcome::Ignored;
    }

    private function record(Payment $payment, PaymentTransactionType $type, ?PaymentStatus $before, ?PaymentStatus $after, GatewayEvent $event, ?string $note = null): void
    {
        PaymentTransaction::create([
            'payment_id' => $payment->id,
            'type' => $type,
            'status_before' => $before?->value,
            'status_after' => $after?->value,
            'amount' => $event->amount?->sen,
            'provider_event_id' => $event->id,
            'note' => $note,
            'payload' => $event->payload,
        ]);
    }

    /** @return array<int, int> product id => units */
    private function demand(Order $order): array
    {
        $demand = [];

        foreach ($order->items as $item) {
            $demand[$item->product_id] = ($demand[$item->product_id] ?? 0) + $item->quantity;
        }

        return $demand;
    }
}
