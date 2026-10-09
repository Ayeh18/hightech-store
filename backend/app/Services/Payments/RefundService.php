<?php

namespace App\Services\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Notifications\RefundRejected;
use App\Notifications\RefundSucceeded;
use App\Services\AuditLogger;
use App\Services\Orders\StockReservation;
use App\Services\Payments\Exceptions\RefundRefused;
use App\Support\Money;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Gives money back. Four ways in, one way out:
 *   - the customer asks (request) and staff approve or decline (approve / reject),
 *   - staff cancel a paid order that has not shipped (cancelPaidOrder),
 *   - staff refund an amount directly: a goodwill refund, or a payment that arrived after its order was cancelled (issue),
 *   - staff retry one that failed (retry).
 * Every refund that is actually sent goes through execute(), so the safety rules live in one place:
 *
 *  - Money never leaves twice: a refund is marked PROCESSING (which counts against what can still be refunded) BEFORE the
 *    gateway is called, and the gateway is given the refund's id as an idempotency key, so a retry after a timeout cannot refund again.
 *  - The gateway is called OUTSIDE the database transaction (a slow gateway must not hold row locks), and the result is
 *    written in a second transaction.
 *  - Everything that changes an order locks the order row first, the same order as payments and cancelling do.
 */
class RefundService
{
    public function __construct(
        private readonly GatewayManager $gateways,
        private readonly StockReservation $stock,
        private readonly AuditLogger $audit,
    ) {}

    /** The customer asks for their money back. Nothing is paid out until staff approve. */
    public function request(Order $order, User $customer, ?string $note): Refund
    {
        return DB::transaction(function () use ($order, $customer, $note) {
            $locked = $this->lockOrder($order->id);

            if (! $locked->canRequestRefund()) {
                throw ValidationException::withMessages(['order' => $this->whyNotRefundable($locked)]);
            }

            $payment = $this->paidPayment($locked);
            $refund = Refund::create([
                'order_id' => $locked->id,
                'payment_id' => $payment->id,
                'amount' => $this->available($locked, $payment),
                'currency' => Money::CURRENCY,
                'status' => RefundStatus::Requested,
                'reason' => RefundReason::CustomerRequest,
                'note' => $note,
                'requested_by' => $customer->id,
            ]);

            $locked->moveTo(OrderStatus::RefundRequested);
            $locked->save();

            $this->audit->log('refund.requested', $customer, $locked, null, ['refund_id' => $refund->id, 'amount' => $refund->amount->sen]);

            return $refund;
        });
    }

    /**
     * Staff say yes. `$restock` says whether the goods go back on the shelf; it defaults to yes only if the order never shipped.
     * Returns the refund: SUCCEEDED, or FAILED if the payment company did not do it (it can be retried).
     */
    public function approve(Refund $refund, User $staff, ?bool $restock = null, ?string $reviewNote = null): Refund
    {
        DB::transaction(function () use ($refund, $staff, $restock, $reviewNote) {
            [$order, $locked] = $this->lockRefund($refund);

            if ($locked->status !== RefundStatus::Requested) {
                throw ValidationException::withMessages(['refund' => 'This refund request has already been dealt with.']);
            }

            $this->assertAvailable($order, $locked);
            $locked->fill([
                'status' => RefundStatus::Processing,
                'restock' => $restock ?? $order->shipment === null,
                'review_note' => $reviewNote,
                'reviewed_by' => $staff->id,
                'reviewed_at' => now(),
            ])->save();

            $this->audit->log('refund.approved', $staff, $order, null, ['refund_id' => $locked->id, 'amount' => $locked->amount->sen, 'restock' => $locked->restock]);
        });

        return $this->execute($refund->id);
    }

    /** Staff say no: the order goes back to exactly the status it had before the request. */
    public function reject(Refund $refund, User $staff, string $reviewNote): Refund
    {
        $notify = [];

        $result = DB::transaction(function () use ($refund, $staff, $reviewNote, &$notify) {
            [$order, $locked] = $this->lockRefund($refund);

            if ($locked->status !== RefundStatus::Requested) {
                throw ValidationException::withMessages(['refund' => 'This refund request has already been dealt with.']);
            }

            $locked->fill(['status' => RefundStatus::Rejected, 'review_note' => $reviewNote, 'reviewed_by' => $staff->id, 'reviewed_at' => now()])->save();

            if ($order->status === OrderStatus::RefundRequested) {
                $order->moveTo($this->statusBeforeRefundRequest($order));
                $order->save();
            }

            $this->audit->log('refund.rejected', $staff, $order, null, ['refund_id' => $locked->id, 'note' => $reviewNote]);
            $notify[] = fn () => $order->user->notify(new RefundRejected($order, $locked));

            return $locked;
        });

        $this->afterCommit($notify);

        return $result;
    }

    /**
     * Staff cancel a paid order that has not shipped: the whole payment is refunded, the order is cancelled and the goods go
     * back on the shelf. If the payment company fails, nothing else changes and the refund can be retried.
     */
    public function cancelPaidOrder(Order $order, User $staff, ?string $note): Refund
    {
        $refund = DB::transaction(function () use ($order, $staff, $note) {
            $locked = $this->lockOrder($order->id);

            if (! in_array($locked->status, [OrderStatus::Paid, OrderStatus::Processing, OrderStatus::Packed], true)) {
                throw ValidationException::withMessages(['order' => 'Only a paid order that has not shipped can be cancelled this way. Use a refund instead.']);
            }

            if ($locked->refunds->contains(fn (Refund $r) => $r->reason === RefundReason::OrderCancelled && in_array($r->status, [RefundStatus::Processing, RefundStatus::Failed], true))) {
                throw ValidationException::withMessages(['order' => 'A refund for this cancellation is already in progress. Retry it instead.']);
            }

            $payment = $this->paidPayment($locked);
            $amount = $this->available($locked, $payment);

            if ($amount->isZero()) {
                throw ValidationException::withMessages(['order' => 'There is nothing left to refund on this order.']);
            }

            $refund = $this->startRefund($locked, $payment, $amount, RefundReason::OrderCancelled, $staff, $note, restock: true);
            $this->audit->log('order.cancel_requested', $staff, $locked, null, ['refund_id' => $refund->id, 'note' => $note]);

            return $refund;
        });

        return $this->execute($refund->id);
    }

    /**
     * Staff refund an amount straight away.
     * Goodwill: any amount up to what is left. LatePayment: the whole of a payment that arrived after its order was cancelled.
     */
    public function issue(Payment $payment, Money $amount, RefundReason $reason, User $staff, ?string $note): Refund
    {
        $refund = DB::transaction(function () use ($payment, $amount, $reason, $staff, $note) {
            $order = $this->lockOrder($payment->order_id);
            $locked = $order->payments->firstWhere('id', $payment->id);

            if (! in_array($reason, [RefundReason::Goodwill, RefundReason::LatePayment], true)) {
                throw ValidationException::withMessages(['reason' => 'Choose goodwill or late payment.']);
            }

            if ($locked->status !== PaymentStatus::Paid) {
                throw ValidationException::withMessages(['payment' => 'Only a paid payment can be refunded.']);
            }

            $available = $this->available($order, $locked);

            if ($amount->isZero() || $amount->isNegative() || $amount->greaterThan($available)) {
                throw ValidationException::withMessages(['amount' => "The refund must be more than RM 0.00 and at most {$available->format()}."]);
            }

            if ($reason === RefundReason::LatePayment && (! $locked->requires_refund || ! $amount->equals($available))) {
                throw ValidationException::withMessages(['reason' => 'A late payment is refunded in full, and only when it is marked as needing a refund.']);
            }

            $refund = $this->startRefund($order, $locked, $amount, $reason, $staff, $note, restock: false);
            $this->audit->log('refund.issued', $staff, $order, null, ['refund_id' => $refund->id, 'amount' => $amount->sen, 'reason' => $reason->value]);

            return $refund;
        });

        return $this->execute($refund->id);
    }

    /** Try a failed refund again. The gateway gets the same idempotency key, so if it had actually gone through, nothing is paid twice. */
    public function retry(Refund $refund, User $staff): Refund
    {
        DB::transaction(function () use ($refund, $staff) {
            [$order, $locked] = $this->lockRefund($refund);

            $stuck = $locked->status === RefundStatus::Processing && $locked->updated_at->lt(now()->subMinutes(2));

            if ($locked->status !== RefundStatus::Failed && ! $stuck) {
                throw ValidationException::withMessages(['refund' => 'Only a refund that failed (or is stuck) can be retried.']);
            }

            $stillValid = match ($locked->reason) {
                RefundReason::CustomerRequest => $order->status === OrderStatus::RefundRequested,
                RefundReason::OrderCancelled => in_array($order->status, [OrderStatus::Paid, OrderStatus::Processing, OrderStatus::Packed], true),
                default => true,
            };

            if (! $stillValid) {
                throw ValidationException::withMessages(['refund' => 'The order has changed since this refund was started, so it cannot be retried.']);
            }

            $this->assertAvailable($order, $locked);
            $locked->fill(['status' => RefundStatus::Processing, 'failure_reason' => null])->save();
            $this->audit->log('refund.retried', $staff, $order, null, ['refund_id' => $locked->id]);
        });

        return $this->execute($refund->id);
    }

    // ------------------------------------------------------------------------------------------------------------

    private function startRefund(Order $order, Payment $payment, Money $amount, RefundReason $reason, User $staff, ?string $note, bool $restock): Refund
    {
        return Refund::create([
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'amount' => $amount,
            'currency' => Money::CURRENCY,
            'status' => RefundStatus::Processing,
            'reason' => $reason,
            'note' => $note,
            'restock' => $restock,
            'requested_by' => $staff->id,
            'reviewed_by' => $staff->id,
            'reviewed_at' => now(),
        ])->setRelation('order', $order);
    }

    /** Sends a PROCESSING refund to the gateway and records what happened. Never throws for a gateway problem: the refund becomes FAILED. */
    private function execute(string $refundId): Refund
    {
        $refund = Refund::with('payment')->findOrFail($refundId);

        try {
            $gateway = $this->gateways->driver($refund->payment->provider);
            $result = $gateway->refundPayment(new RefundRequest(
                (string) $refund->payment->provider_reference,
                $refund->amount,
                $refund->id,
                $refund->reason->label(),
            ));
        } catch (Throwable $e) {
            if (! $e instanceof RefundRefused) {
                report($e);
            }

            return $this->fail($refund->id, $e instanceof RefundRefused ? $e->getMessage() : 'The payment company could not be reached. Try again in a moment.');
        }

        return $this->complete($refund->id, $result->providerReference);
    }

    private function fail(string $refundId, string $why): Refund
    {
        return DB::transaction(function () use ($refundId, $why) {
            $refund = Refund::findOrFail($refundId);
            [$order, $locked] = $this->lockRefund($refund);

            if ($locked->status === RefundStatus::Processing) {
                $locked->fill(['status' => RefundStatus::Failed, 'failure_reason' => $why])->save();
                $this->audit->log('refund.failed', null, $order, null, ['refund_id' => $locked->id, 'reason' => $why]);
            }

            return $locked;
        });
    }

    /** The gateway has paid out: record it, and carry out what the refund means for the payment, the order and the stock. */
    private function complete(string $refundId, string $providerReference): Refund
    {
        $notify = [];

        $result = DB::transaction(function () use ($refundId, $providerReference, &$notify) {
            [$order, $refund] = $this->lockRefund(Refund::findOrFail($refundId));

            if ($refund->status !== RefundStatus::Processing) {
                return $refund; // finished by someone else in the meantime
            }

            $payment = $order->payments->firstWhere('id', $refund->payment_id);
            $payment->refunded_amount = $payment->refunded_amount->add($refund->amount);
            $fully = $payment->refunded_amount->equals($payment->amount);

            if ($fully) {
                $payment->status = PaymentStatus::Refunded;
                $payment->requires_refund = false;
            }
            $payment->save();

            $refund->fill(['status' => RefundStatus::Succeeded, 'provider_reference' => $providerReference, 'refunded_at' => now(), 'failure_reason' => null])->save();

            $this->applyToOrder($order, $refund, $fully);

            if ($refund->restock) {
                $this->stock->restock($this->demand($order), $order, $refund->reason === RefundReason::OrderCancelled ? 'order cancelled' : 'goods returned');
            }

            $this->audit->log('refund.succeeded', null, $order, null, [
                'refund_id' => $refund->id, 'amount' => $refund->amount->sen, 'fully_refunded' => $fully, 'restocked' => $refund->restock,
            ]);
            $notify[] = fn () => $order->user->notify(new RefundSucceeded($order, $refund));

            return $refund;
        });

        $this->afterCommit($notify);

        return $result;
    }

    private function applyToOrder(Order $order, Refund $refund, bool $fullyRefunded): void
    {
        switch ($refund->reason) {
            case RefundReason::CustomerRequest:
                if ($order->status === OrderStatus::RefundRequested) {
                    $order->moveTo(OrderStatus::Refunded);
                }
                break;
            case RefundReason::OrderCancelled:
                if (in_array($order->status, [OrderStatus::Paid, OrderStatus::Processing, OrderStatus::Packed], true)) {
                    $order->moveTo(OrderStatus::Cancelled);
                    $order->cancelled_at = now();
                }
                break;
            default:
                // A late payment or goodwill refund: the order is not changed, only the money.
                break;
        }

        // A late payment's order was already cancelled and its own payment status says so; leave it alone.
        if ($fullyRefunded && $refund->reason !== RefundReason::LatePayment) {
            $order->payment_status = PaymentStatus::Refunded;
        }

        if ($order->isDirty()) {
            $order->save();
        }
    }

    // ------------------------------------------------------------------------------------------------------------

    /** Locks the order row (the first lock, always) and loads what the refund rules need. */
    private function lockOrder(string $orderId): Order
    {
        $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();

        return $order->load(['items', 'payments', 'refunds', 'shipment', 'user']);
    }

    /** @return array{0: Order, 1: Refund} the locked order and the locked refund */
    private function lockRefund(Refund $refund): array
    {
        $order = $this->lockOrder($refund->order_id);
        $locked = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();

        return [$order, $locked];
    }

    private function paidPayment(Order $order): Payment
    {
        $payment = $order->payments->first(fn (Payment $p) => $p->status === PaymentStatus::Paid && ! $p->requires_refund);

        if ($payment === null) {
            throw ValidationException::withMessages(['order' => 'This order has no completed payment to refund.']);
        }

        return $payment;
    }

    /** What can still be refunded on a payment: what was paid, minus what is refunded, minus refunds being sent right now. */
    private function available(Order $order, Payment $payment, ?string $exceptRefundId = null): Money
    {
        $inFlight = $order->refunds
            ->filter(fn (Refund $r) => $r->payment_id === $payment->id && $r->status === RefundStatus::Processing && $r->id !== $exceptRefundId)
            ->reduce(fn (Money $sum, Refund $r) => $sum->add($r->amount), Money::zero());

        return $payment->refundable()->subtract($inFlight);
    }

    private function assertAvailable(Order $order, Refund $refund): void
    {
        $payment = $order->payments->firstWhere('id', $refund->payment_id);
        $available = $payment->status === PaymentStatus::Paid ? $this->available($order, $payment, $refund->id) : Money::zero();

        if ($refund->amount->greaterThan($available)) {
            throw ValidationException::withMessages(['refund' => "Only {$available->format()} can still be refunded on this payment."]);
        }
    }

    private function whyNotRefundable(Order $order): string
    {
        if ($order->refunds->contains(fn (Refund $r) => $r->status === RefundStatus::Requested)) {
            return 'You have already asked for a refund on this order. We will review it soon.';
        }

        if ($order->status === OrderStatus::Delivered) {
            return 'The refund period for this order has ended.';
        }

        return 'This order cannot be refunded right now.';
    }

    private function statusBeforeRefundRequest(Order $order): OrderStatus
    {
        $history = $order->statusHistory()->where('to_status', OrderStatus::RefundRequested->value)->latest('id')->first();

        return $history?->from_status ?? OrderStatus::Paid;
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

    /** @param  array<int, Closure>  $callbacks */
    private function afterCommit(array $callbacks): void
    {
        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $e) {
                report($e); // a mail problem must not undo a refund that already happened
            }
        }
    }
}
