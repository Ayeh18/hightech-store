<?php

namespace App\Http\Resources\Admin;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment as staff see it. The gateway's own reference is for people allowed to view payments.
 *
 * @mixin Payment
 */
class AdminPaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'provider' => $this->provider,
            'provider_reference' => $this->when($request->user()?->can('payments.view'), $this->provider_reference),
            'amount' => $this->amount,
            'refunded_amount' => $this->refunded_amount,
            'refundable' => $this->refundable(),
            'method' => $this->method,
            'requires_refund' => $this->requires_refund,
            'failure_reason' => $this->failure_reason,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'order' => $this->whenLoaded('order', fn () => [
                'id' => $this->order->id,
                'order_number' => $this->order->order_number,
                'customer' => $this->order->relationLoaded('user') ? ['name' => $this->order->user->name, 'email' => $this->order->user->email] : null,
            ]),
        ];
    }
}
