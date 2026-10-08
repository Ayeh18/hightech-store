<?php

namespace App\Services\Compatibility\Rules;

use App\Enums\BuilderSlot;
use App\Services\Compatibility\AbstractRule;
use App\Services\Compatibility\BuildContext;
use App\Services\Compatibility\RuleSettings;

class RamModulesFitMotherboard extends AbstractRule
{
    public function key(): string
    {
        return 'ram_modules_fit_motherboard';
    }

    public function name(): string
    {
        return 'RAM modules fit the memory slots';
    }

    public function description(): string
    {
        return 'The total number of memory sticks (kit size x kits) cannot exceed the motherboard memory slots.';
    }

    public function specs(): array
    {
        return ['ram' => ['modules'], 'motherboard' => ['ram_slots']];
    }

    public function evaluate(BuildContext $build, RuleSettings $settings): array
    {
        $ram = $build->part(BuilderSlot::Ram);
        $modules = $ram?->number('modules');
        $slots = $build->part(BuilderSlot::Motherboard)?->number('ram_slots');

        if ($ram === null || $modules === null || $slots === null) {
            return [];
        }

        $total = $modules * $ram->quantity;

        if ($total <= $slots) {
            return [];
        }

        return [$this->issue($settings, "You selected {$total} memory modules, but the motherboard only has {$slots} memory slots.", BuilderSlot::Ram, BuilderSlot::Motherboard)];
    }
}
