<?php

namespace Database\Factories;

use App\Models\Place;
use App\Models\SchoolDate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SchoolDate> */
class SchoolDateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'place_id' => Place::factory(),
            'kind' => 'term',
            'name' => 'Autumn term',
            'starts_on' => '2026-09-02',
            'ends_on' => '2026-10-22',
        ];
    }
}
