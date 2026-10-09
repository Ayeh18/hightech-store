<?php

namespace App\Http\Resources\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** A full order. Requires Order::DETAIL_RELATIONS to be loaded. @mixin Order */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'payment_status' => ['value' => $this->payment_status->value, 'label' => $this->payment_status->label()],
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'shipping_total' => $this->shipping_total,
            'total' => $this->total,
            'coupon_code' => $this->coupon_code,
            'shipping_address' => [
                'name' => $this->shipping_name,
                'phone' => $this->shipping_phone,
                'line1' => $this->shipping_line1,
                'line2' => $this->shipping_line2,
                'city' => $this->shipping_city,
                'state' => $this->shipping_state,
                'postcode' => $this->shipping_postcode,
                'country' => $this->shipping_country,
            ],
            'notes' => $this->notes,
            'lines' => $this->lines(),
            'can_cancel' => $this->status->isCancellableByCustomer() && $this->isUnpaid(),
            'can_pay' => $this->canBePaid(),
            // The newest payment attempt (payments are loaded newest first), so the page knows what to show and whether to wait.
            'payment' => $this->payments->isNotEmpty() ? new PaymentResource($this->payments->first()) : null,
            // Money arrived that the order could not use (it had been cancelled): it will be refunded.
            'refund_pending' => $this->payments->contains(fn ($payment) => $payment->requires_refund),
            'can_request_refund' => $this->canRequestRefund(),
            'shipment' => $this->shipment ? new ShipmentResource($this->shipment) : null,
            'refunds' => RefundResource::collection($this->refunds),
            // The order's story, oldest first: placed, paid, packed, shipped...
            'timeline' => $this->statusHistory->map(fn ($h) => [
                'status' => ['value' => $h->to_status->value, 'label' => $h->to_status->label()],
                'at' => $h->created_at->toIso8601String(),
            ])->values()->all(),
            'expires_at' => $this->isUnpaid() ? $this->expires_at?->toIso8601String() : null,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'placed_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * The order lines as the customer thinks of them: single products, and each PC with its parts together.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function lines(): array
    {
        return $this->items
            ->groupBy(fn (OrderItem $item) => $item->build_group ?? "single-{$item->id}")
            ->map(function (Collection $group) {
                $first = $group->first();

                if ($first->build_group === null) {
                    return ['kind' => 'product', ...$this->part($first), 'line_total' => $first->line_total];
                }

                return [
                    'kind' => 'build',
                    'name' => $first->build_name,
                    'copies' => $first->build_copies,
                    'line_total' => Money::fromSen($group->sum(fn (OrderItem $i) => $i->line_total->sen)),
                    'parts' => $group->map(fn (OrderItem $i) => ['slot' => $i->slot?->value, 'slot_label' => $i->slot?->label(), ...$this->part($i), 'line_total' => $i->line_total])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    protected function part(OrderItem $item): array
    {
        return ['name' => $item->name, 'sku' => $item->sku, 'quantity' => $item->quantity, 'unit_price' => $item->unit_price];
    }
}
