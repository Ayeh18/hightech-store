<?php

namespace App\Http\Resources\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of "My orders". Requires `items` to be loaded.
 *
 * @mixin Order
 */
class OrderSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // A PC counts as one thing, however many parts it has.
        $names = $this->items->map(fn (OrderItem $i) => $i->build_name ?? $i->name)->unique()->values();

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'payment_status' => ['value' => $this->payment_status->value, 'label' => $this->payment_status->label()],
            'total' => $this->total,
            'preview' => $names->take(3)->all(),
            'more' => max(0, $names->count() - 3),
            'placed_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
