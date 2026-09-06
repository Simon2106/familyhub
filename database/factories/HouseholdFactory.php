<?php

namespace Database\Factories;

use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Household> */
class HouseholdFactory extends Factory
{
    protected $model = Household::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->lastName().' Family',
            'timezone' => 'Europe/London',
        ];
    }
}
