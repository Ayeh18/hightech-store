<?php

namespace App\Services\Compatibility\Rules;

use App\Enums\BuilderSlot;
use App\Services\Compatibility\AbstractRule;
use App\Services\Compatibility\BuildContext;
use App\Services\Compatibility\RuleSettings;

class CpuSocketMatchesMotherboard extends AbstractRule
{
    public function key(): string
    {
        return 'cpu_socket_matches_motherboard';
    }

    public function name(): string
    {
        return 'CPU socket matches motherboard';
    }

    public function description(): string
    {
        return 'The CPU and the motherboard must use the same socket (e.g. AM5 with AM5).';
    }

    public function specs(): array
    {
        return ['cpu' => ['socket'], 'motherboard' => ['socket']];
    }

    public function evaluate(BuildContext $build, RuleSettings $settings): array
    {
        $cpu = $build->part(BuilderSlot::Cpu)?->text('socket');
        $board = $build->part(BuilderSlot::Motherboard)?->text('socket');

        if ($cpu === null || $board === null || $cpu === $board) {
            return [];
        }

        return [$this->issue($settings, "CPU socket {$cpu} is not compatible with motherboard socket {$board}.", BuilderSlot::Cpu, BuilderSlot::Motherboard)];
    }
}
