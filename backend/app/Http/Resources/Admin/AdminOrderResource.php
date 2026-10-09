<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\Orders\OrderResource;
use Illuminate\Http\Request;

/** The whole order for staff: everything the customer sees, plus who it is and every payment. Needs `user` and Order::DETAIL_RELATIONS. */
class AdminOrderResource extends OrderResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'customer' => ['id' => $this->user->id, 'name' => $this->user->name, 'email' => $this->user->email],
            'payments' => AdminPaymentResource::collection($this->payments),
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }
}
