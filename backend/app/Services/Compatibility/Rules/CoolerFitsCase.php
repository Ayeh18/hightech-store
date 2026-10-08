<?php

namespace App\Services\Compatibility\Rules;

use App\Enums\BuilderSlot;
use App\Services\Compatibility\AbstractRule;
use App\Services\Compatibility\BuildContext;
use App\Services\Compatibility\RuleSettings;

class CoolerFitsCase extends AbstractRule
{
    public function key(): string
    {
        return 'cooler_fits_case';
    }

    public function name(): string
    {
        return 'CPU cooler fits the case';
    }

    public function description(): string
    {
        return "An air cooler cannot be taller than the case's maximum CPU cooler height. (Liquid coolers have no height and are skipped.)";
    }

    public function specs(): array
    {
        return ['cooler' => ['height_mm'], 'case' => ['cpu_cooler_max_height_mm']];
    }

    public function evaluate(BuildContext $build, RuleSettings $settings): array
    {
        $height = $build->part(BuilderSlot::Cooler)?->number('height_mm');
        $max = $build->part(BuilderSlot::Case_)?->number('cpu_cooler_max_height_mm');

        if ($height === null || $max === null || $height <= $max) {
            return [];
        }

        return [$this->issue($settings, "Cooler height {$height} mm exceeds the case's maximum CPU cooler height of {$max} mm.", BuilderSlot::Cooler, BuilderSlot::Case_)];
    }
}
