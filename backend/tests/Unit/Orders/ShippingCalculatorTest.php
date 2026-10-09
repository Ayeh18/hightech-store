<?php

use App\Enums\MalaysianState;
use App\Services\Orders\ShippingCalculator;
use App\Support\Money;

it('uses the West Malaysia rate by default and the East Malaysia rate for Sabah, Sarawak and Labuan', function () {
    $shipping = new ShippingCalculator;
    $order = Money::fromSen(100000);

    expect($shipping->cost(MalaysianState::PulauPinang, $order)->sen)->toBe(1500)
        ->and($shipping->cost('Selangor', $order)->sen)->toBe(1500)
        ->and($shipping->cost(MalaysianState::Sabah, $order)->sen)->toBe(3500)
        ->and($shipping->cost('Sarawak', $order)->sen)->toBe(3500)
        ->and($shipping->cost(MalaysianState::Labuan, $order)->sen)->toBe(3500);
});

it('is free at exactly the threshold and above, and paid just below it', function () {
    $shipping = new ShippingCalculator;

    expect($shipping->cost(MalaysianState::Sabah, Money::fromSen(299999))->sen)->toBe(3500)
        ->and($shipping->cost(MalaysianState::Sabah, Money::fromSen(300000))->sen)->toBe(0)
        ->and($shipping->cost(MalaysianState::Selangor, Money::fromSen(1_000_000))->sen)->toBe(0);
});

it('falls back to the West Malaysia rate for an unknown state', function () {
    expect((new ShippingCalculator)->cost('Atlantis', Money::fromSen(1000))->sen)->toBe(1500);
});

it('follows the configured rates', function () {
    config(['shop.shipping.west_malaysia' => 999, 'shop.shipping.free_over' => 5000]);

    expect((new ShippingCalculator)->cost('Johor', Money::fromSen(4999))->sen)->toBe(999)
        ->and((new ShippingCalculator)->cost('Johor', Money::fromSen(5000))->sen)->toBe(0);
});
