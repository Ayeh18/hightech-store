<?php

namespace Database\Seeders;

use App\Enums\CouponType;
use App\Models\Coupon;
use App\Support\Money;
use Illuminate\Database\Seeder;

/** Demo coupons for local development. Safe to run repeatedly. */
class CouponSeeder extends Seeder
{
    public function run(): void
    {
        Coupon::firstOrCreate(['code' => 'WELCOME10'], [
            'description' => '10% off your first order over RM 1,000',
            'type' => CouponType::Percent,
            'value' => 1000,
            'min_order_amount' => Money::fromRinggit('1000'),
            'per_user_limit' => 1,
            'is_active' => true,
        ]);

        Coupon::firstOrCreate(['code' => 'SAVE50'], [
            'description' => 'RM 50 off orders over RM 500',
            'type' => CouponType::Fixed,
            'value' => 5000,
            'min_order_amount' => Money::fromRinggit('500'),
            'per_user_limit' => 3,
            'is_active' => true,
        ]);
    }
}
