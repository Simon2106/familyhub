<?php

namespace Database\Factories;

use App\Models\Calendar;
use App\Models\CalendarAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Calendar> */
class CalendarFactory extends Factory
{
    protected $model = Calendar::class;

    public function definition(): array
    {
        return [
            'calendar_account_id' => CalendarAccount::factory(),
            'external_id' => $this->faker->unique()->uuid(),
            'name' => $this->faker->word(),
            'colour' => '#2563eb',
            'is_visible' => true,
            'is_writable' => true,
        ];
    }

    public function hidden(): static
    {
        return $this->state(fn () => ['is_visible' => false]);
    }
}
