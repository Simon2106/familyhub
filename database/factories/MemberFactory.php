<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Member> */
class MemberFactory extends Factory
{
    protected $model = Member::class;

    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'name' => $this->faker->firstName(),
            'colour' => $this->faker->randomElement(['#2563eb', '#db2777', '#16a34a', '#ea580c']),
            'is_child' => false,
            'sort_order' => 0,
        ];
    }

    public function child(string $pin = '1234'): static
    {
        return $this->state(fn () => ['is_child' => true, 'pin' => $pin]);
    }
}
