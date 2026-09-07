<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Reward;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Reward> */
class RewardFactory extends Factory
{
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'name' => 'An hour of screen time',
            'cost' => 20,
        ];
    }
}
