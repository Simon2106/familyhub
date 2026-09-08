<?php

namespace Database\Factories;

use App\Models\BinCollection;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BinCollection> */
class BinCollectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'on' => now()->addDay()->toDateString(),
            'name' => 'Recycling collection',
            'kind' => 'recycling',
        ];
    }
}
