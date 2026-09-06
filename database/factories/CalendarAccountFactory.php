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
            'provider' => CalendarAccount::PROVIDER_ICLOUD,
            'label' => 'Test account',
            'external_account_id' => $this->faker->unique()->safeEmail(),
            'status' => 'ok',
            'principal_url' => '/12345678/principal/',
            'calendar_home_url' => '/12345678/calendars/',
            'credentials' => [
                'username' => $this->faker->unique()->safeEmail(),
                'password' => 'abcd-efgh-ijkl-mnop',
                'base_url' => 'https://caldav.icloud.com',
            ],
        ];
    }

    public function broken(string $error = 'Authentication failed'): static
    {
        return $this->state(fn () => ['status' => 'error', 'last_error' => $error]);
    }
}
