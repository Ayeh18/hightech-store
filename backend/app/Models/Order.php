<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function couponUsage(): HasOne
    {
        return $this->hasOne(CouponUsage::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('created_at')->latest('id');
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
