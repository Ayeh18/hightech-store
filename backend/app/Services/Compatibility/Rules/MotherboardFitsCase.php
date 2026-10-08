<?php

namespace App\Services\Compatibility\Rules;

use App\Enums\BuilderSlot;
use App\Services\Compatibility\AbstractRule;
use App\Services\Compatibility\BuildContext;
use App\Services\Compatibility\RuleSettings;

class MotherboardFitsCase extends AbstractRule
{
    public function key(): string
    {
        return 'motherboard_fits_case';
    }

    public function name(): string
    {
        return 'Motherboard fits the case';
    }

    public function description(): string
    {
        return "The motherboard's form factor (ATX, Micro ATX, ...) must be supported by the case.";
    }

    public function specs(): array
    {
        return ['motherboard' => ['form_factor'], 'case' => ['supported_form_factors']];
    }

    public function evaluate(BuildContext $build, RuleSettings $settings): array
    {
        $form = $build->part(BuilderSlot::Motherboard)?->text('form_factor');
        $supported = $build->part(BuilderSlot::Case_)?->list('supported_form_factors');

        if ($form === null || $supported === null || in_array($form, $supported, true)) {
            return [];
        }

        return [$this->issue($settings, "A {$form} motherboard does not fit this case (it supports ".implode(', ', $supported).').', BuilderSlot::Motherboard, BuilderSlot::Case_)];
    }
}
