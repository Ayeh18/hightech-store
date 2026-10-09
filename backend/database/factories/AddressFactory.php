<?php

namespace Database\Factories;

use App\Enums\MalaysianState;
use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Address> */
class AddressFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => 'Home',
            'recipient_name' => fake()->name(),
            'phone' => fake()->numerify('01#-#######'),
            'line1' => fake()->streetAddress(),
            'line2' => null,
            'city' => 'Petaling Jaya',
            'state' => MalaysianState::Selangor->value,
            'postcode' => '47810',
            'country' => 'MY',
            'is_default' => false,
        ];
    }

    public function inState(MalaysianState $state): static
    {
        return $this->state(['state' => $state->value]);
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }
}
