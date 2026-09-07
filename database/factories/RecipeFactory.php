<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Recipe> */
class RecipeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'title' => 'Chicken and chorizo traybake',
            'source_kind' => 'url',
            'status' => 'ready',
            'servings' => 4,
            'ingredients' => [
                ['quantity' => 4.0, 'unit' => null, 'item' => 'chicken thigh', 'note' => 'bone in'],
                ['quantity' => 200.0, 'unit' => 'g', 'item' => 'chorizo', 'note' => null],
            ],
            'steps' => ['Heat the oven to 200C.', 'Roast for 40 minutes.'],
            'tags' => ['quick'],
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => 'pending', 'ingredients' => null, 'steps' => null, 'tags' => null]);
    }

    public function failed(string $error = 'That link could not be opened.'): static
    {
        return $this->state(['status' => 'failed', 'error' => $error]);
    }
}
