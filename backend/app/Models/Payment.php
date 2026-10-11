<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\PaymentStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'order_id', 'provider', 'provider_reference', 'status', 'amount', 'refunded_amount', 'currency', 'method', 'checkout_url',
    'failure_reason', 'requires_refund', 'paid_at', 'failed_at', 'cancelled_at',
])]
class Payment extends Model
{
    use HasUlids;

    protected $attributes = ['refunded_amount' => 0];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => MoneyCast::class,
            'refunded_amount' => MoneyCast::class,
            'requires_refund' => 'boolean',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<PaymentTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class)->orderBy('id');
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->latest('created_at')->latest('id');
    }

    /** Money still available to give back: what was paid minus what has been refunded. */
    public function refundable(): Money
    {
        return $this->status === PaymentStatus::Paid ? $this->amount->subtract($this->refunded_amount) : Money::zero();
    }

    /** Still waiting for the customer or the gateway. */
    public function isOpen(): bool
    {
        return in_array($this->status, [PaymentStatus::Pending, PaymentStatus::Processing], true);
    }
}
