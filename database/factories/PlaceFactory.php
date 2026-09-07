<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Place;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Place> */
class PlaceFactory extends Factory
{
    protected $model = Place::class;

    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'name' => $this->faker->unique()->company(),
            'type' => 'other',
        ];
    }

    public function school(): static
    {
        return $this->state(fn () => ['type' => 'school']);
    }

    public function work(): static
    {
        return $this->state(fn () => ['type' => 'work']);
    }
}
