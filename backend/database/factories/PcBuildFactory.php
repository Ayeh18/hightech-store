<?php

namespace Database\Factories;

use App\Models\PcBuild;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PcBuild> */
class PcBuildFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true).' build',
        ];
    }
}
