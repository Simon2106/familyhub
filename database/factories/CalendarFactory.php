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
            // CalDAV calendars are addressed by collection path, not by id.
            'external_id' => '/12345678/calendars/'.$this->faker->unique()->slug(2).'/',
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

    public function syncable(string $token = 'sync-token-1'): static
    {
        return $this->state(fn () => ['supports_sync_collection' => true, 'sync_token' => $token]);
    }

    public function readOnly(): static
    {
        return $this->state(fn () => ['is_writable' => false]);
    }
}
