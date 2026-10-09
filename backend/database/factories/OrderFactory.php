<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    /** An unpaid order for RM 1,000.00 + RM 15.00 shipping (the totals always add up: the database checks it). */
    public function definition(): array
    {
        return [
            'order_number' => 'ORD-2026-'.fake()->unique()->numerify('#####'),
            'user_id' => User::factory(),
            'status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'subtotal' => Money::fromSen(100000),
            'discount_total' => Money::zero(),
            'shipping_total' => Money::fromSen(1500),
            'total' => Money::fromSen(101500),
            'currency' => 'MYR',
            'shipping_name' => fake()->name(),
            'shipping_phone' => fake()->numerify('01#-#######'),
            'shipping_line1' => fake()->streetAddress(),
            'shipping_city' => 'Petaling Jaya',
            'shipping_state' => 'Selangor',
            'shipping_postcode' => '47810',
            'shipping_country' => 'MY',
            'expires_at' => now()->addMinutes(30),
        ];
    }

    public function paid(): static
    {
        return $this->state(['status' => OrderStatus::Paid, 'payment_status' => PaymentStatus::Paid, 'expires_at' => null]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }
}
