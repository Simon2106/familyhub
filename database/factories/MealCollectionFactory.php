<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\MealCollection;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MealCollection> */
class MealCollectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'name' => 'Sunday roasts',
        ];
    }
}
