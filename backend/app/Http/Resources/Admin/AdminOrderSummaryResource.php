<?php

namespace App\Http\Resources\Admin;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One row of the staff order list. Needs `user` and `items_count`. @mixin Order */
class AdminOrderSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'customer' => ['name' => $this->user->name, 'email' => $this->user->email],
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'payment_status' => ['value' => $this->payment_status->value, 'label' => $this->payment_status->label()],
            'total' => $this->total,
            'items_count' => $this->items_count,
            'placed_at' => $this->created_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }
}
