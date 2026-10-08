<?php

namespace App\Services\Compatibility\Rules;

use App\Enums\BuilderSlot;
use App\Services\Compatibility\AbstractRule;
use App\Services\Compatibility\BuildContext;
use App\Services\Compatibility\CompatibilityIssue;
use App\Services\Compatibility\RuleSettings;

class CpuCoolerRequired extends AbstractRule
{
    public function key(): string
    {
        return 'cpu_cooler_required';
    }

    public function name(): string
    {
        return 'CPU without a bundled cooler needs one';
    }

    public function description(): string
    {
        return 'Some CPUs ship without a cooler. Without one the CPU will overheat.';
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
        return ['cpu' => ['cooler_included']];
    }

    public function evaluate(BuildContext $build, RuleSettings $settings): array
    {
        $cpu = $build->part(BuilderSlot::Cpu);

        if ($cpu === null || $cpu->text('cooler_included') !== 'No' || $build->part(BuilderSlot::Cooler) !== null) {
            return [];
        }

        return [$this->issue($settings, "{$cpu->name} does not include a cooler. Add a CPU cooler.", BuilderSlot::Cpu, BuilderSlot::Cooler)];
    }
}
