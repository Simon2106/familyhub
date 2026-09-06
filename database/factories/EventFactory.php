<?php

namespace Database\Factories;

use App\Models\Calendar;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<Event> */
class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        $start = Carbon::today()->addHours($this->faker->numberBetween(8, 18));

        return [
            'calendar_id' => Calendar::factory(),
            'external_id' => $this->faker->unique()->uuid(),
            'title' => $this->faker->sentence(3),
            'start_at' => $start,
            'end_at' => (clone $start)->addHour(),
            'all_day' => false,
            'status' => 'confirmed',
        ];
    }

    public function allDay(?Carbon $day = null): static
    {
        $day ??= Carbon::today();

        return $this->state(fn () => [
            'all_day' => true,
            'start_at' => $day->copy()->startOfDay(),
            'end_at' => $day->copy()->endOfDay(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => 'cancelled']);
    }
}
