<?php

namespace App\Services\Compatibility;

use App\Enums\BuilderSlot;

/**
 * Rough peak power draw of a build: CPU + GPU + a flat allowance for everything else
 * (motherboard, memory, drives, fans). It is an estimate, not a measurement.
 */
final class PowerEstimator
{
    public const ROUND_UP_TO_WATTS = 50;

    /** Returns null when neither the CPU nor the GPU is known, so there is nothing sensible to estimate. */
    public function estimateWatts(BuildContext $build, int $baseSystemWatts): ?int
    {
        $cpu = $build->part(BuilderSlot::Cpu)?->number('tdp_watts');
        $gpu = $build->part(BuilderSlot::Gpu)?->number('tdp_watts');

        if ($cpu === null && $gpu === null) {
            return null;
        }

        return (int) round(($cpu ?? 0) + ($gpu ?? 0) + $baseSystemWatts);
    }

    /** The estimate plus headroom, rounded up to a purchasable size: 420 W + 30% = 546 -> 550 W. */
    public function recommendedWatts(int $estimatedWatts, int $headroomPercent): int
    {
        $withHeadroom = (int) ceil($estimatedWatts * (100 + $headroomPercent) / 100);

        return (int) (ceil($withHeadroom / self::ROUND_UP_TO_WATTS) * self::ROUND_UP_TO_WATTS);
    }
}
