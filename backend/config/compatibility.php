<?php

use App\Services\Compatibility\Rules;

return [
    /*
    |--------------------------------------------------------------------------
    | Compatibility rules
    |--------------------------------------------------------------------------
    | Every rule class listed here is checked on every build. To add a rule:
    |   1. create a class in app/Services/Compatibility/Rules (extend AbstractRule),
    |   2. add it to this list,
    |   3. run `php artisan db:seed --class=CompatibilityRuleSeeder` to create its settings row.
    | Admins can then switch it on/off, change its severity and tune its numbers
    | without a deployment (see the compatibility_rules table).
    */
    'rules' => [
        Rules\CpuSocketMatchesMotherboard::class,
        Rules\RamTypeMatchesMotherboard::class,
        Rules\RamModulesFitMotherboard::class,
        Rules\RamCapacityWithinMotherboardLimit::class,
        Rules\MotherboardFitsCase::class,
        Rules\GpuFitsCase::class,
        Rules\CoolerFitsCase::class,
        Rules\CoolerSupportsCpuSocket::class,
        Rules\CoolerCoolsCpu::class,
        Rules\PsuCoversEstimatedLoad::class,
        Rules\PsuHasHeadroom::class,
        Rules\PsuMeetsGpuRecommendation::class,
        Rules\PsuFitsCase::class,
        Rules\StorageFitsM2Slots::class,
        Rules\CpuNeedsGraphicsCard::class,
        Rules\CpuCoolerRequired::class,
    ],
];
