<?php

namespace Database\Factories;

use App\Enums\SpecType;
use App\Models\Category;
use App\Models\SpecDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SpecDefinition> */
class SpecDefinitionFactory extends Factory
{
    public function definition(): array
    {
        $label = fake()->unique()->words(2, true);

        return [
            'category_id' => Category::factory(),
            'key' => Str::snake($label),
            'label' => Str::title($label),
            'type' => SpecType::String,
            'unit' => null,
            'options' => null,
            'is_required' => true,
            'is_filterable' => false,
            'show_on_card' => false,
            'sort_order' => 0,
        ];
    }

    /** @param  array<int, string>  $options */
    public function enum(array $options): static
    {
        return $this->state(['type' => SpecType::Enum, 'options' => $options]);
    }

    /** @param  array<int, string>  $options */
    public function list(array $options): static
    {
        return $this->state(['type' => SpecType::List, 'options' => $options]);
    }

    public function number(?string $unit = null): static
    {
        return $this->state(['type' => SpecType::Number, 'unit' => $unit]);
    }

    public function filterable(): static
    {
        return $this->state(['is_filterable' => true]);
    }
}
