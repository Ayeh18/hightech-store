<?php

namespace App\Services\Compatibility\Rules;

use App\Enums\BuilderSlot;
use App\Services\Compatibility\AbstractRule;
use App\Services\Compatibility\BuildContext;
use App\Services\Compatibility\RuleSettings;

class CoolerSupportsCpuSocket extends AbstractRule
{
    public function key(): string
    {
        return 'cooler_supports_cpu_socket';
    }

    public function name(): string
    {
        return 'Cooler supports the CPU socket';
    }

    public function description(): string
    {
        return "The cooler's mounting kit must support the CPU's socket.";
    }

    public function specs(): array
    {
        return ['cpu' => ['socket'], 'cooler' => ['supported_sockets']];
    }

    public function evaluate(BuildContext $build, RuleSettings $settings): array
    {
        $socket = $build->part(BuilderSlot::Cpu)?->text('socket');
        $supported = $build->part(BuilderSlot::Cooler)?->list('supported_sockets');

        if ($socket === null || $supported === null || in_array($socket, $supported, true)) {
            return [];
        }

        return [$this->issue($settings, "This cooler does not support the {$socket} socket (it supports ".implode(', ', $supported).').', BuilderSlot::Cpu, BuilderSlot::Cooler)];
    }
}
