<?php

namespace App\Services\Compatibility\Rules;

use App\Enums\BuilderSlot;
use App\Services\Compatibility\AbstractRule;
use App\Services\Compatibility\BuildContext;
use App\Services\Compatibility\CompatibilityIssue;
use App\Services\Compatibility\RuleSettings;

class PsuMeetsGpuRecommendation extends AbstractRule
{
    public function key(): string
    {
        return 'psu_meets_gpu_recommendation';
    }

    public function name(): string
    {
        return "Power supply meets the GPU maker's recommendation";
    }

    public function description(): string
    {
        return 'Graphics card makers publish a recommended power supply size. Falling short is a warning.';
    }

    public function defaultSeverity(): string
    {
        return CompatibilityIssue::WARNING;
    }

    public function specs(): array
    {
        return ['gpu' => ['recommended_psu_watts'], 'psu' => ['wattage']];
    }

    public function evaluate(BuildContext $build, RuleSettings $settings): array
    {
        $recommended = $build->part(BuilderSlot::Gpu)?->number('recommended_psu_watts');
        $wattage = $build->part(BuilderSlot::Psu)?->number('wattage');

        if ($recommended === null || $wattage === null || $wattage >= $recommended) {
            return [];
        }

        return [$this->issue($settings, "The GPU manufacturer recommends at least {$recommended} W; you selected {$wattage} W.", BuilderSlot::Gpu, BuilderSlot::Psu)];
    }
}
