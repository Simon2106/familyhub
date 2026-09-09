<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\Recipe;
use App\Models\RecipeRating;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RecipeRating> */
class RecipeRatingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'recipe_id' => Recipe::factory(),
            'member_id' => Member::factory(),
            'stars' => 4,
        ];
    }

    public function thumbsUp(): static
    {
        return $this->state(['stars' => null, 'thumbs' => 1]);
    }

    public function thumbsDown(): static
    {
        return $this->state(['stars' => null, 'thumbs' => -1]);
    }
}
