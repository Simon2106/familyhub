<?php

namespace App\Models;

use App\Casts\CalendarDate;
use Database\Factories\MealFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One square on the weekly planner. */
#[Fillable(['household_id', 'on', 'slot', 'title', 'recipe_id'])]
class Meal extends Model
{
    /** @use HasFactory<MealFactory> */
    use HasFactory;

    /** In the order they happen, which is the order they are shown in. */
    public const SLOTS = ['breakfast', 'lunch', 'dinner'];

    /** Dinner is the one every household plans; the others are opt-in. */
    public const DEFAULT_SLOTS = ['dinner'];

    protected function casts(): array
    {
        return ['on' => CalendarDate::class];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** Optional. A meal never requires a recipe. */
    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /** The key the week grid buckets by. */
    public function cell(): string
    {
        return $this->on->toDateString().'|'.$this->slot;
    }

    /** Only a linked recipe can contribute to a shopping list. */
    public function hasIngredients(): bool
    {
        return $this->recipe?->hasIngredients() ?? false;
    }

    /** @param Builder<Meal> $query */
    public function scopeBetween(Builder $query, string $from, string $to): void
    {
        $query->whereBetween('on', [$from, $to]);
    }
}
