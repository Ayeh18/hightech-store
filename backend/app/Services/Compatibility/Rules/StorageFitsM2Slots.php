<?php

namespace App\Services\Compatibility\Rules;

use App\Enums\BuilderSlot;
use App\Services\Compatibility\AbstractRule;
use App\Services\Compatibility\BuildContext;
use App\Services\Compatibility\RuleSettings;

class StorageFitsM2Slots extends AbstractRule
{
    public function key(): string
    {
        return 'storage_fits_m2_slots';
    }

    public function name(): string
    {
        return 'M.2 drives fit the motherboard slots';
    }

    public function description(): string
    {
        return 'The number of M.2 drives cannot exceed the motherboard M.2 slots.';
    }

    public function specs(): array
    {
        return ['storage' => ['form_factor'], 'motherboard' => ['m2_slots']];
    }

    public function evaluate(BuildContext $build, RuleSettings $settings): array
    {
        $storage = $build->part(BuilderSlot::Storage);
        $slots = $build->part(BuilderSlot::Motherboard)?->number('m2_slots');

        if ($storage === null || $slots === null || ! str_starts_with((string) $storage->text('form_factor'), 'M.2')) {
            return [];
        }

        if ($storage->quantity <= $slots) {
            return [];
        }

        return [$this->issue($settings, "You selected {$storage->quantity} M.2 drives, but the motherboard only has {$slots} M.2 slots.", BuilderSlot::Storage, BuilderSlot::Motherboard)];
    }
}
