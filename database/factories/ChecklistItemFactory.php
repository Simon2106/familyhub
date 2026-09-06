<?php

namespace Database\Factories;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChecklistItem> */
class ChecklistItemFactory extends Factory
{
    protected $model = ChecklistItem::class;

    public function definition(): array
    {
        return [
            'checklist_id' => Checklist::factory(),
            'title' => $this->faker->sentence(2),
            'is_done' => false,
        ];
    }

    public function done(): static
    {
        return $this->state(fn () => ['is_done' => true, 'done_at' => now()]);
    }
}
