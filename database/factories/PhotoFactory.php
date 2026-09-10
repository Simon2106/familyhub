<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Photo;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Photo> */
class PhotoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'source' => 'upload',
            'disk' => 'public',
            'path' => 'photos/'.$this->faker->unique()->slug(2).'.jpg',
        ];
    }
}
