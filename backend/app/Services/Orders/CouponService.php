<?php

namespace App\Services\Orders;

use App\Enums\CouponType;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\User;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/** Decides whether a coupon code may be used on an order and how much it takes off. */
class CouponService
{
    /**
     * @param  bool  $lock  at checkout, lock the coupon row so two simultaneous orders cannot both use its last redemption
     *
     * @throws ValidationException with a customer-friendly message on field `coupon_code`
     */
    public function apply(string $code, User $user, Money $subtotal, bool $lock = false): CouponApplication
    {
        $query = Coupon::where('code', Coupon::normalize($code));
        $coupon = ($lock ? $query->lockForUpdate() : $query)->first();

        if ($coupon === null || ! $coupon->is_active) {
            $this->fail('That coupon code is not valid.');
        }

        if ($coupon->starts_at?->isFuture()) {
            $this->fail('This coupon is not active yet.');
        }

        if ($coupon->ends_at?->isPast()) {
            $this->fail('This coupon has expired.');
        }

        if ($subtotal->lessThan($coupon->min_order_amount)) {
            $this->fail("Spend at least {$coupon->min_order_amount->format()} to use {$coupon->code}.");
        }

        if ($coupon->usage_limit !== null && $this->redemptions($coupon) >= $coupon->usage_limit) {
            $this->fail('This coupon has been fully redeemed.');
        }

        if ($coupon->per_user_limit !== null && $this->redemptions($coupon, $user) >= $coupon->per_user_limit) {
            $this->fail("You have already used {$coupon->code}.");
        }

        return new CouponApplication($coupon, $this->discountFor($coupon, $subtotal));
    }

    /** The amount taken off. Never more than the order value, and a percentage coupon can have a cap. */
    public function discountFor(Coupon $coupon, Money $subtotal): Money
    {
        $discount = match ($coupon->type) {
            CouponType::Percent => $subtotal->percent((int) $coupon->value),
            CouponType::Fixed => Money::fromSen((int) $coupon->value),
        };

        if ($coupon->max_discount_amount !== null) {
            $discount = $discount->min($coupon->max_discount_amount);
        }

        return $discount->min($subtotal);
    }

    /** How many times the coupon is currently in use. Orders that were cancelled or expired have released theirs. */
    private function redemptions(Coupon $coupon, ?User $user = null): int
    {
        return CouponUsage::where('coupon_id', $coupon->id)
            ->whereNull('released_at')
            ->when($user, fn ($q) => $q->where('user_id', $user->id))
            ->count();
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['coupon_code' => $message]);
    }
}
