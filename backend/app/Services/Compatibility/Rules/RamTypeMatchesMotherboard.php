<?php

namespace App\Services\Compatibility\Rules;

use App\Enums\BuilderSlot;
use App\Services\Compatibility\AbstractRule;
use App\Services\Compatibility\BuildContext;
use App\Services\Compatibility\RuleSettings;

class RamTypeMatchesMotherboard extends AbstractRule
{
    public function key(): string
    {
        return 'ram_type_matches_motherboard';
    }

    public function name(): string
    {
        return 'RAM type matches motherboard';
    }

    public function description(): string
    {
        return 'DDR4 memory only works on DDR4 boards and DDR5 memory only on DDR5 boards.';
    }

    public function specs(): array
    {
        return ['ram' => ['ram_type'], 'motherboard' => ['ram_type']];
    }

    public function evaluate(BuildContext $build, RuleSettings $settings): array
    {
        $ram = $build->part(BuilderSlot::Ram)?->text('ram_type');
        $board = $build->part(BuilderSlot::Motherboard)?->text('ram_type');

        if ($ram === null || $board === null || $ram === $board) {
            return [];
        }

        return [$this->issue($settings, "The motherboard supports {$board} memory, but the selected RAM is {$ram}.", BuilderSlot::Ram, BuilderSlot::Motherboard)];
    }
}
