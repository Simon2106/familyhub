<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Meal;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Meal> */
class MealFactory extends Factory
{
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'on' => now()->toDateString(),
            'slot' => 'dinner',
            'title' => 'Leftovers',
        ];
    }
}
