<?php

use App\Enums\BuilderSlot;
use App\Services\Compatibility\BuildContext;
use App\Services\Compatibility\Part;
use App\Services\Compatibility\PowerEstimator;

it('adds CPU, GPU and the base allowance', function () {
    $build = BuildContext::of([
        new Part(BuilderSlot::Cpu, 'cpu', ['tdp_watts' => 120]),
        new Part(BuilderSlot::Gpu, 'gpu', ['tdp_watts' => 220]),
    ]);

    expect((new PowerEstimator)->estimateWatts($build, 80))->toBe(420);
});

it('returns null when neither CPU nor GPU power is known', function () {
    expect((new PowerEstimator)->estimateWatts(BuildContext::of([]), 80))->toBeNull()
        ->and((new PowerEstimator)->estimateWatts(BuildContext::of([new Part(BuilderSlot::Cpu, 'cpu', [])]), 80))->toBeNull();
});

it('rounds the recommendation up to the next 50 W', function (int $load, int $headroom, int $expected) {
    expect((new PowerEstimator)->recommendedWatts($load, $headroom))->toBe($expected);
})->with([
    'brief example' => [420, 30, 550],   // 546 -> 550
    'exact multiple' => [500, 30, 650],  // 650 stays 650
    'just over' => [501, 30, 700],       // 651.3 -> 652 -> 700
    'no headroom' => [420, 0, 450],
    'double' => [420, 100, 850],         // 840 -> 850
]);
