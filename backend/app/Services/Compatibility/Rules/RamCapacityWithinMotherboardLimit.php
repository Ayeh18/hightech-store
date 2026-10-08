<?php

namespace App\Services\Compatibility\Rules;

use App\Enums\BuilderSlot;
use App\Services\Compatibility\AbstractRule;
use App\Services\Compatibility\BuildContext;
use App\Services\Compatibility\RuleSettings;

class RamCapacityWithinMotherboardLimit extends AbstractRule
{
    public function key(): string
    {
        return 'ram_capacity_within_motherboard_limit';
    }

    public function name(): string
    {
        return 'RAM capacity within motherboard limit';
    }

    public function description(): string
    {
        return 'The total memory (GB per kit x kits) cannot exceed what the motherboard supports.';
    }

    public function specs(): array
    {
        return ['ram' => ['capacity_gb'], 'motherboard' => ['max_ram_gb']];
    }

    public function evaluate(BuildContext $build, RuleSettings $settings): array
    {
        $ram = $build->part(BuilderSlot::Ram);
        $capacity = $ram?->number('capacity_gb');
        $max = $build->part(BuilderSlot::Motherboard)?->number('max_ram_gb');

        if ($ram === null || $capacity === null || $max === null) {
            return [];
        }

        $total = $capacity * $ram->quantity;

        if ($total <= $max) {
            return [];
        }

        return [$this->issue($settings, "{$total} GB of RAM exceeds the motherboard's maximum of {$max} GB.", BuilderSlot::Ram, BuilderSlot::Motherboard)];
    }
}
