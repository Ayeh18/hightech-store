<?php

namespace App\Http\Resources\Admin;

use App\Enums\CouponType;
use App\Models\Coupon;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A coupon for admins. `used_count` (redemptions that still count) comes from withCount.
 *
 * @mixin Coupon
 */
class CouponResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'description' => $this->description,
            'type' => $this->type->value,
            // percent: basis points (1000 = 10%). fixed: sen. `value_display` is the same thing in words.
            'value' => $this->value,
            'value_display' => $this->type === CouponType::Percent
                ? rtrim(rtrim(number_format($this->value / 100, 2, '.', ''), '0'), '.').'%'
                : Money::fromSen($this->value)->format(),
            'min_order_amount' => $this->min_order_amount,
            'max_discount_amount' => $this->max_discount_amount,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'usage_limit' => $this->usage_limit,
            'per_user_limit' => $this->per_user_limit,
            'is_active' => $this->is_active,
            'used_count' => (int) ($this->used_count ?? 0),
            'status' => $this->statusNow(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** Why a customer can or cannot use it right now: inactive, scheduled, expired, used_up or active. */
    private function statusNow(): string
    {
        return match (true) {
            ! $this->is_active => 'inactive',
            $this->starts_at && $this->starts_at->isFuture() => 'scheduled',
            $this->ends_at && $this->ends_at->isPast() => 'expired',
            $this->usage_limit !== null && (int) ($this->used_count ?? 0) >= $this->usage_limit => 'used_up',
            default => 'active',
        };
    }
}
