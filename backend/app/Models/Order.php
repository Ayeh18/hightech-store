<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Observers\OrderObserver;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

#[ObservedBy(OrderObserver::class)]
#[Fillable([
    'order_number', 'user_id', 'status', 'payment_status', 'subtotal', 'discount_total', 'shipping_total', 'total', 'currency',
    'coupon_id', 'coupon_code', 'shipping_name', 'shipping_phone', 'shipping_line1', 'shipping_line2', 'shipping_city',
    'shipping_state', 'shipping_postcode', 'shipping_country', 'notes', 'idempotency_key', 'expires_at', 'cancelled_at', 'paid_at',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'subtotal' => MoneyCast::class,
            'discount_total' => MoneyCast::class,
            'shipping_total' => MoneyCast::class,
            'total' => MoneyCast::class,
            'expires_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return HasOne<CouponUsage, $this> */
    public function couponUsage(): HasOne
    {
        return $this->hasOne(CouponUsage::class);
    }

    /** @return HasOne<Shipment, $this> */
    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class);
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->latest('created_at')->latest('id');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('created_at')->latest('id');
    }

    /** Everything an order page needs, in one eager load (so listing or showing orders never runs a query per row). */
    public const DETAIL_RELATIONS = ['items', 'payments', 'refunds', 'shipment', 'statusHistory'];

    /**
     * May the customer ask for their money back? Needs `payments`, `refunds` and `shipment` to be loaded.
     * Not once it has been refunded or cancelled, not twice at the same time, and for a delivered order only within the
     * refund window.
     */
    public function canRequestRefund(): bool
    {
        $refundable = [OrderStatus::Paid, OrderStatus::Processing, OrderStatus::Packed, OrderStatus::Shipped, OrderStatus::Delivered];

        if (! in_array($this->status, $refundable, true)) {
            return false;
        }

        $payment = $this->payments->first(fn (Payment $p) => $p->status === PaymentStatus::Paid && ! $p->requires_refund);

        if ($payment === null || $payment->refundable()->isZero()) {
            return false;
        }

        if ($this->refunds->contains(fn (Refund $r) => $r->status === RefundStatus::Requested)) {
            return false;
        }

        if ($this->status === OrderStatus::Delivered) {
            $deliveredAt = $this->shipment?->delivered_at;

            return $deliveredAt === null || $deliveredAt->gt(now()->subDays((int) config('shop.refund_window_days')));
        }

        return true;
    }

    /** Can the customer start (or restart) a payment right now? Unpaid, and still inside the payment window. */
    public function canBePaid(): bool
    {
        return $this->isUnpaid() && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    /** Nothing has been paid yet, so the stock it holds can still be given back. */
    public function isUnpaid(): bool
    {
        return $this->status === OrderStatus::PendingPayment
            && in_array($this->payment_status, [PaymentStatus::Pending, PaymentStatus::Failed], true);
    }

    /** Moves the order to a new status, but only along an allowed path. Anything else is a bug and throws. */
    public function moveTo(OrderStatus $next): void
    {
        if (! $this->status->canMoveTo($next)) {
            throw new LogicException("An order cannot go from {$this->status->value} to {$next->value}.");
        }

        $this->status = $next;
    }
}
