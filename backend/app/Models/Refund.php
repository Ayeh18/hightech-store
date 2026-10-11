<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id', 'payment_id', 'amount', 'currency', 'status', 'reason', 'note', 'review_note', 'restock', 'requested_by', 'reviewed_by',
    'reviewed_at', 'provider_reference', 'failure_reason', 'refunded_at',
])]
class Refund extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'reason' => RefundReason::class,
            'amount' => MoneyCast::class,
            'restock' => 'boolean',
            'reviewed_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
