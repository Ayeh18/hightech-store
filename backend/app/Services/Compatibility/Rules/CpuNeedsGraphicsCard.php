<?php

namespace App\Services\Compatibility\Rules;

use App\Enums\BuilderSlot;
use App\Services\Compatibility\AbstractRule;
use App\Services\Compatibility\BuildContext;
use App\Services\Compatibility\CompatibilityIssue;
use App\Services\Compatibility\RuleSettings;

class CpuNeedsGraphicsCard extends AbstractRule
{
    public function key(): string
    {
        return 'cpu_needs_graphics_card';
    }

    public function name(): string
    {
        return 'CPU without graphics needs a graphics card';
    }

    public function description(): string
    {
        return 'A CPU with no integrated graphics cannot drive a display on its own.';
    }

    public function defaultSeverity(): string
    {
        return CompatibilityIssue::WARNING;
    }

    public function usesMissingParts(): bool
    {
        return true;
    }

    public function specs(): array
    {
        return ['cpu' => ['integrated_graphics']];
    }

    public function evaluate(BuildContext $build, RuleSettings $settings): array
    {
        $cpu = $build->part(BuilderSlot::Cpu);

        if ($cpu === null || $cpu->text('integrated_graphics') !== 'No' || $build->part(BuilderSlot::Gpu) !== null) {
            return [];
        }

        return [$this->issue($settings, "{$cpu->name} has no integrated graphics, so you need a graphics card to use a display.", BuilderSlot::Cpu, BuilderSlot::Gpu)];
    }
}
