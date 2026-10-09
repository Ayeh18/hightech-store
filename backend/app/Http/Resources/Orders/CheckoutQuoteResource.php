<?php

namespace App\Http\Resources\Orders;

use App\Services\Orders\CheckoutQuote;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CheckoutQuote */
class CheckoutQuoteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'shipping' => $this->shipping,
            'shipping_known' => $this->shipping !== null,
            'total' => $this->total,
            'free_shipping_over' => Money::fromSen((int) config('shop.shipping.free_over')),
            'payment_window_minutes' => (int) config('shop.payment_window_minutes'),
            'coupon' => [
                'code' => $this->coupon?->code,
                'applied' => $this->coupon !== null,
                'error' => $this->couponError,
            ],
            'blockers' => $this->blockers,
            'can_place' => $this->canPlace(),
        ];
    }
}
