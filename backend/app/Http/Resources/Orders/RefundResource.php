<?php

namespace App\Http\Resources\Orders;

use App\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A refund. Customers see their own words and what staff said; staff also see the internal details.
 *
 * @mixin Refund
 */
class RefundResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $staff = $request->user()?->can('orders.manage') ?? false;

        return [
            'id' => $this->id,
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'reason' => ['value' => $this->reason->value, 'label' => $this->reason->label()],
            'amount' => $this->amount,
            'note' => $this->note,
            'review_note' => $this->review_note,
            'requested_at' => $this->created_at?->toIso8601String(),
            'refunded_at' => $this->refunded_at?->toIso8601String(),
            // What only staff need: why the payment company said no, and whether the goods go back on the shelf.
            'failure_reason' => $this->when($staff, $this->failure_reason),
            'restock' => $this->when($staff, $this->restock),
            'payment_id' => $this->when($staff, $this->payment_id),
            // For the staff queue: which order and customer this is about (load `order.user` first).
            'order' => $this->when($staff && $this->relationLoaded('order') && $this->order->relationLoaded('user'), fn () => [
                'id' => $this->order->id,
                'order_number' => $this->order->order_number,
                'status' => ['value' => $this->order->status->value, 'label' => $this->order->status->label()],
                'customer' => ['name' => $this->order->user->name, 'email' => $this->order->user->email],
            ]),
        ];
    }
}
