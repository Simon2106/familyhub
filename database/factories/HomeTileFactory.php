<?php

namespace Database\Factories;

use App\Models\HomeTile;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HomeTile> */
class HomeTileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'entity_id' => 'light.kitchen',
            'domain' => 'light',
            'name' => 'Kitchen spots',
            'area' => 'Kitchen',
        ];
    }
}
