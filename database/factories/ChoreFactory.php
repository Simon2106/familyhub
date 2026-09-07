<?php

namespace Database\Factories;

use App\Models\Chore;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Chore> */
class ChoreFactory extends Factory
{
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'title' => 'Feed the cat',
            'recurrence' => 'daily',
            'points' => 2,
        ];
    }

    public function needingApproval(): static
    {
        return $this->state(['needs_approval' => true]);
    }
}
