<?php

namespace Database\Factories;

use App\Models\Capture;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Capture> */
class CaptureFactory extends Factory
{
    protected $model = Capture::class;

    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'source' => 'email',
            'status' => 'pending',
            'subject' => $this->faker->sentence(4),
            'sender' => $this->faker->safeEmail(),
            'body_text' => $this->faker->paragraph(),
        ];
    }

    public function reviewing(): static
    {
        return $this->state(fn () => ['status' => 'reviewing', 'processed_at' => now()]);
    }

    public function failed(string $error = 'Something went wrong.'): static
    {
        return $this->state(fn () => ['status' => 'failed', 'error' => $error]);
    }
}
