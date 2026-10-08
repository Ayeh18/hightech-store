<?php

namespace App\Services\Compatibility\Rules;

use App\Enums\BuilderSlot;
use App\Services\Compatibility\AbstractRule;
use App\Services\Compatibility\BuildContext;
use App\Services\Compatibility\PowerEstimator;
use App\Services\Compatibility\RuleSettings;

class PsuCoversEstimatedLoad extends AbstractRule
{
    public function __construct(private readonly PowerEstimator $power = new PowerEstimator) {}

    public function key(): string
    {
        return 'psu_covers_estimated_load';
    }

    public function name(): string
    {
        return 'Power supply covers the estimated load';
    }

    public function description(): string
    {
        return 'The power supply wattage must be at least the estimated peak draw: CPU + GPU + a flat allowance for everything else.';
    }

    public function defaultParams(): array
    {
        return ['base_system_watts' => 80];
    }

    public function paramRules(): array
    {
        return ['base_system_watts' => ['integer', 'min:0', 'max:1000']];
    }

    public function specs(): array
    {
        return ['cpu' => ['tdp_watts'], 'gpu' => ['tdp_watts'], 'psu' => ['wattage']];
    }

    public function evaluate(BuildContext $build, RuleSettings $settings): array
    {
        $wattage = $build->part(BuilderSlot::Psu)?->number('wattage');
        $load = $this->power->estimateWatts($build, (int) $settings->param('base_system_watts', 80));

        if ($wattage === null || $load === null || $wattage >= $load) {
            return [];
        }

        return [$this->issue($settings, "The {$wattage} W power supply is below the estimated system draw of {$load} W.", BuilderSlot::Psu, BuilderSlot::Cpu, BuilderSlot::Gpu)];
    }
}
