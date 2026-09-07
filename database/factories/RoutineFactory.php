<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Member;
use App\Models\Routine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Routine> */
class RoutineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'member_id' => Member::factory(),
            'kind' => 'morning',
            'name' => 'Morning',
            'starts_at' => '07:00:00',
            'ends_at' => '08:30:00',
        ];
    }
}
