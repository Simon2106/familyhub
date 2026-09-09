<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\SwitchGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SwitchGroup> */
class SwitchGroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'name' => 'Lamps',
        ];
    }
}
