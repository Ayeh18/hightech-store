<?php

namespace App\Services\Compatibility\Rules;

use App\Enums\BuilderSlot;
use App\Services\Compatibility\AbstractRule;
use App\Services\Compatibility\BuildContext;
use App\Services\Compatibility\CompatibilityIssue;
use App\Services\Compatibility\RuleSettings;

class CoolerCoolsCpu extends AbstractRule
{
    public function key(): string
    {
        return 'cooler_cools_cpu';
    }

    public function name(): string
    {
        return 'Cooler can handle the CPU heat';
    }

    public function description(): string
    {
        return "The cooler's rated cooling capacity should be at least the CPU's TDP.";
    }

    public function defaultSeverity(): string
    {
        return CompatibilityIssue::WARNING;
    }

    public function specs(): array
    {
        return ['cpu' => ['tdp_watts'], 'cooler' => ['tdp_rating_watts']];
    }

    public function evaluate(BuildContext $build, RuleSettings $settings): array
    {
        $tdp = $build->part(BuilderSlot::Cpu)?->number('tdp_watts');
        $rating = $build->part(BuilderSlot::Cooler)?->number('tdp_rating_watts');

        if ($tdp === null || $rating === null || $rating >= $tdp) {
            return [];
        }

        return [$this->issue($settings, "The cooler is rated for {$rating} W but the CPU has a TDP of {$tdp} W.", BuilderSlot::Cpu, BuilderSlot::Cooler)];
    }
}
