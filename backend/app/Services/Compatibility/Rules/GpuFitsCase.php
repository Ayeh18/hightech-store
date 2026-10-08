<?php

namespace App\Services\Compatibility\Rules;

use App\Enums\BuilderSlot;
use App\Services\Compatibility\AbstractRule;
use App\Services\Compatibility\BuildContext;
use App\Services\Compatibility\RuleSettings;

class GpuFitsCase extends AbstractRule
{
    public function key(): string
    {
        return 'gpu_fits_case';
    }

    public function name(): string
    {
        return 'GPU fits the case';
    }

    public function description(): string
    {
        return "The graphics card cannot be longer than the case's maximum supported GPU length.";
    }

    public function specs(): array
    {
        return ['gpu' => ['length_mm'], 'case' => ['gpu_max_length_mm']];
    }

    public function evaluate(BuildContext $build, RuleSettings $settings): array
    {
        $length = $build->part(BuilderSlot::Gpu)?->number('length_mm');
        $max = $build->part(BuilderSlot::Case_)?->number('gpu_max_length_mm');

        if ($length === null || $max === null || $length <= $max) {
            return [];
        }

        return [$this->issue($settings, "GPU length {$length} mm exceeds the case's maximum supported GPU length of {$max} mm.", BuilderSlot::Gpu, BuilderSlot::Case_)];
    }
}
