<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\Orders\RefundResource;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Http\Request;

/**
 * One payment with its diary (every step it went through) and its refunds. Needs order.user, transactions and refunds.
 *
 * @mixin Payment
 */
class AdminPaymentDetailResource extends AdminPaymentResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            // The raw payload the gateway sent is deliberately left out: the diary says what happened, not what was posted.
            'transactions' => $this->transactions->map(fn ($t) => [
                'id' => $t->id,
                'type' => $t->type->value,
                'status_before' => $t->status_before,
                'status_after' => $t->status_after,
                'amount' => $t->amount === null ? null : Money::fromSen($t->amount),
                'provider_event_id' => $t->provider_event_id,
                'note' => $t->note,
                'at' => $t->created_at->toIso8601String(),
            ])->values()->all(),
            'refunds' => RefundResource::collection($this->refunds),
        ];
    }
}
