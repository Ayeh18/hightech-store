<?php

namespace App\Http\Resources\Orders;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One payment attempt, as the customer needs to see it.
 *
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'provider' => $this->provider,
            'amount' => $this->amount,
            'method' => $this->method,
            // Only an attempt that is still open has a page to go to.
            'checkout_url' => $this->isOpen() ? $this->checkout_url : null,
            'failure_reason' => $this->failure_reason,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
