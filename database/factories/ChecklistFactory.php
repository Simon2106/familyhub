<?php

namespace Database\Factories;

use App\Models\Checklist;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Checklist> */
class ChecklistFactory extends Factory
{
    protected $model = Checklist::class;

    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'name' => $this->faker->word(),
            'type' => 'todo',
        ];
    }
}
