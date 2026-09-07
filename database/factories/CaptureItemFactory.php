<?php

namespace Database\Factories;

use App\Models\Capture;
use App\Models\CaptureItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CaptureItem> */
class CaptureItemFactory extends Factory
{
    protected $model = CaptureItem::class;

    public function definition(): array
    {
        $start = CarbonImmutable::now()->addDays(7)->setTime(9, 0);

        return [
            'capture_id' => Capture::factory(),
            'type' => 'event',
            'title' => $this->faker->sentence(3),
            'start_at' => $start,
            'end_at' => $start->addHour(),
            'all_day' => false,
            'confidence' => 90,
            'status' => 'pending',
        ];
    }

    public function unsure(int $confidence = 40): static
    {
        return $this->state(fn () => ['confidence' => $confidence]);
    }

    public function task(): static
    {
        return $this->state(fn () => ['type' => 'task', 'end_at' => null]);
    }

    public function undated(): static
    {
        return $this->state(fn () => ['start_at' => null, 'end_at' => null]);
    }
}
