<?php

namespace App\Services\Compatibility\Rules;

use App\Enums\BuilderSlot;
use App\Services\Compatibility\AbstractRule;
use App\Services\Compatibility\BuildContext;
use App\Services\Compatibility\RuleSettings;

class PsuFitsCase extends AbstractRule
{
    public function key(): string
    {
        return 'psu_fits_case';
    }

    public function name(): string
    {
        return 'Power supply fits the case';
    }

    public function description(): string
    {
        return 'A case built only for compact SFX power supplies cannot take a full-size ATX one. (An SFX unit in an ATX case works with a bracket, so it is allowed.)';
    }

    public function specs(): array
    {
        return ['psu' => ['form_factor'], 'case' => ['psu_form_factor']];
    }

    public function evaluate(BuildContext $build, RuleSettings $settings): array
    {
        $psu = $build->part(BuilderSlot::Psu)?->text('form_factor');
        $case = $build->part(BuilderSlot::Case_)?->text('psu_form_factor');

        if ($psu === null || $case === null || ! ($case === 'SFX' && $psu === 'ATX')) {
            return [];
        }

        return [$this->issue($settings, 'This case only fits SFX power supplies, but the selected power supply is ATX.', BuilderSlot::Psu, BuilderSlot::Case_)];
    }
}
