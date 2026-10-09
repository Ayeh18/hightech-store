<?php

namespace Database\Factories;

use App\Enums\CouponType;
use App\Models\Coupon;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Coupon> */
class CouponFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('SAVE-????-##')),
            'description' => fake()->sentence(3),
            'type' => CouponType::Percent,
            'value' => 1000, // 10%
            'min_order_amount' => Money::zero(),
            'max_discount_amount' => null,
            'starts_at' => null,
            'ends_at' => null,
            'usage_limit' => null,
            'per_user_limit' => 1,
            'is_active' => true,
        ];
    }

    public function percent(int $basisPoints): static
    {
        return $this->state(['type' => CouponType::Percent, 'value' => $basisPoints]);
    }

    public function fixed(int $sen): static
    {
        return $this->state(['type' => CouponType::Fixed, 'value' => $sen]);
    }

    public function minOrder(int $sen): static
    {
        return $this->state(['min_order_amount' => Money::fromSen($sen)]);
    }
}
