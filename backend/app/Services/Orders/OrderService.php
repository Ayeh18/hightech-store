<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionType;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Cancelling orders, and giving back the stock and coupon an unpaid order was holding. */
class OrderService
{
    public function __construct(private readonly StockReservation $stock, private readonly AuditLogger $audit) {}

    /**
     * Cancels an UNPAID order and releases its stock and coupon. Safe to call twice: the second call finds the order
     * already cancelled and refuses, so stock is never released more than once.
     *
     * @throws ValidationException when the order can no longer be cancelled this way
     */
    public function cancel(Order $order, ?User $actor, string $reason): Order
    {
        return DB::transaction(function () use ($order, $actor, $reason) {
            // Lock the row and re-read it: another request (a payment arriving, the expiry job) may have changed it.
            $locked = Order::whereKey($order->id)->lockForUpdate()->with(['items', 'couponUsage'])->firstOrFail();

            if (! $locked->isUnpaid()) {
                throw ValidationException::withMessages(['order' => 'This order can no longer be cancelled.']);
            }

            $demand = [];
            foreach ($locked->items as $item) {
                $demand[$item->product_id] = ($demand[$item->product_id] ?? 0) + $item->quantity;
            }
            $this->stock->release($demand);

            $locked->couponUsage?->update(['released_at' => now()]);

            // A payment page that is still waiting is closed too. (If the customer pays on it anyway, the money is
            // recorded and flagged for a refund; see PaymentProcessor.)
            Payment::where('order_id', $locked->id)->where('status', PaymentStatus::Pending->value)->lockForUpdate()->get()
                ->each(function (Payment $payment) use ($reason) {
                    $payment->fill(['status' => PaymentStatus::Cancelled, 'cancelled_at' => now()])->save();
                    PaymentTransaction::create([
                        'payment_id' => $payment->id,
                        'type' => PaymentTransactionType::Cancelled,
                        'status_before' => PaymentStatus::Pending->value,
                        'status_after' => PaymentStatus::Cancelled->value,
                        'note' => "Order cancelled: {$reason}",
                    ]);
                });

            $from = $locked->status;
            $locked->moveTo(OrderStatus::Cancelled);
            $locked->payment_status = PaymentStatus::Cancelled;
            $locked->cancelled_at = now();
            $locked->save();

            $this->audit->log('order.cancelled', $actor, $locked, ['status' => $from->value], ['status' => OrderStatus::Cancelled->value, 'reason' => $reason]);

            return $locked;
        });
    }

    /**
     * Cancels every unpaid order whose payment window has passed, returning its stock to the shop.
     * Run every minute by the scheduler. Returns how many orders were expired.
     */
    public function expireOverdue(): int
    {
        $ids = Order::where('status', OrderStatus::PendingPayment->value)
            ->whereIn('payment_status', [PaymentStatus::Pending->value, PaymentStatus::Failed->value])
            ->where('expires_at', '<', now())
            ->pluck('id');

        $expired = 0;

        foreach ($ids as $id) {
            try {
                $this->cancel(Order::findOrFail($id), null, 'Payment window expired');
                $expired++;
            } catch (ValidationException) {
                // It was paid or cancelled a moment ago; nothing to do.
            }
        }

        return $expired;
    }
}
