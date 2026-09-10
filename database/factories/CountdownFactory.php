<?php

namespace Database\Factories;

use App\Models\Countdown;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Countdown> */
class CountdownFactory extends Factory
{
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'label' => 'Cornwall',
            'on' => now()->addDays(12)->toDateString(),
        ];
    }
}
