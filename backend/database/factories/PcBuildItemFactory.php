<?php

namespace Database\Factories;

use App\Enums\BuilderSlot;
use App\Models\PcBuild;
use App\Models\PcBuildItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PcBuildItem> */
class PcBuildItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'pc_build_id' => PcBuild::factory(),
            'product_id' => Product::factory(),
            'slot' => BuilderSlot::Cpu,
            'quantity' => 1,
        ];
    }
}
