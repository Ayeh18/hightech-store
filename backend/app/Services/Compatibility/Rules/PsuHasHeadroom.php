<?php

namespace App\Services\Compatibility\Rules;

use App\Enums\BuilderSlot;
use App\Services\Compatibility\AbstractRule;
use App\Services\Compatibility\BuildContext;
use App\Services\Compatibility\CompatibilityIssue;
use App\Services\Compatibility\PowerEstimator;
use App\Services\Compatibility\RuleSettings;

class PsuHasHeadroom extends AbstractRule
{
    public function __construct(private readonly PowerEstimator $power = new PowerEstimator) {}

    public function key(): string
    {
        return 'psu_has_headroom';
    }

    public function name(): string
    {
        return 'Power supply has headroom';
    }

    public function description(): string
    {
        return 'A power supply should be comfortably above the estimated draw (default 30% headroom) so it runs efficiently and has room to upgrade.';
    }

    public function defaultSeverity(): string
    {
        return CompatibilityIssue::WARNING;
    }

    public function defaultParams(): array
    {
        return ['base_system_watts' => 80, 'headroom_percent' => 30];
    }

    public function paramRules(): array
    {
        return [
            'base_system_watts' => ['integer', 'min:0', 'max:1000'],
            'headroom_percent' => ['integer', 'min:0', 'max:200'],
        ];
    }

    public function specs(): array
    {
        return ['cpu' => ['tdp_watts'], 'gpu' => ['tdp_watts'], 'psu' => ['wattage']];
    }

    public function evaluate(BuildContext $build, RuleSettings $settings): array
    {
        $wattage = $build->part(BuilderSlot::Psu)?->number('wattage');
        $load = $this->power->estimateWatts($build, (int) $settings->param('base_system_watts', 80));

        if ($wattage === null || $load === null || $wattage < $load) {
            return []; // an outright shortfall is reported by psu_covers_estimated_load
        }

        $recommended = $this->power->recommendedWatts($load, (int) $settings->param('headroom_percent', 30));

        if ($wattage >= $recommended) {
            return [];
        }

        return [$this->issue($settings, "Estimated draw is {$load} W. A power supply of at least {$recommended} W is recommended; you selected {$wattage} W.", BuilderSlot::Psu, BuilderSlot::Cpu, BuilderSlot::Gpu)];
    }
}
