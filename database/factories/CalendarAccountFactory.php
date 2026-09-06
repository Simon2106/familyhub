<?php

namespace Database\Factories;

use App\Models\CalendarAccount;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CalendarAccount> */
class CalendarAccountFactory extends Factory
{
    protected $model = CalendarAccount::class;

    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'provider' => CalendarAccount::PROVIDER_GOOGLE,
            'label' => 'Test account',
            'external_account_id' => $this->faker->unique()->safeEmail(),
            'status' => 'ok',
        ];
    }

    public function icloud(): static
    {
        return $this->state(fn () => ['provider' => CalendarAccount::PROVIDER_ICLOUD]);
    }
}
