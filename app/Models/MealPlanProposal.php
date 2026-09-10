<?php

namespace App\Models;

use App\Casts\CalendarDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A week somebody asked the assistant for, waiting to be looked at.
 *
 * This is emphatically not the meal plan. Every row here is a suggestion the
 * planner draws in ghost text until a grown-up taps Keep, and the whole thing
 * is thrown away the moment it is accepted or dismissed. Nothing reads this
 * table except the planner.
 *
 * An entry may name a recipe the family already has, or it may be an idea
 * nobody has written down yet — "a leek and potato soup" — in which case
 * recipe_id is null and accepting it puts the idea in the box.
 */
#[Fillable(['household_id', 'week_start', 'slot', 'entries', 'asked_for', 'note'])]
class MealPlanProposal extends Model
{
    /** A proposal nobody has looked at by now has been overtaken by the week. */
    public const KEEP_DAYS = 14;

    protected function casts(): array
    {
        // CalendarDate rather than date, for the same reason as everywhere
        // else: Eloquent's date cast writes a time, MySQL truncates it and
        // SQLite does not.
        return [
            'week_start' => CalendarDate::class,
            'entries' => 'array',
        ];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * The one live proposal for a week, if there is one.
     *
     * @param  Builder<MealPlanProposal>  $query
     */
    public function scopeForWeek(Builder $query, Household $household, CarbonImmutable $weekStart, string $slot = 'dinner'): void
    {
        $query->where('household_id', $household->id)
            ->where('week_start', $weekStart->toDateString())
            ->where('slot', $slot);
    }

    /**
     * The entries, filled out to a shape the planner can draw without guarding
     * four keys — they arrive from a language model, so a missing one is not
     * an exceptional case.
     *
     * @return list<array{on: string, title: string, recipe_id: int|null, why: string|null}>
     */
    public function entryList(): array
    {
        return array_values(array_map(
            fn (array $row) => [
                'on' => (string) $row['on'],
                'title' => (string) $row['title'],
                'recipe_id' => isset($row['recipe_id']) && $row['recipe_id'] ? (int) $row['recipe_id'] : null,
                'why' => filled($row['why'] ?? null) ? (string) $row['why'] : null,
            ],
            array_filter(
                (array) ($this->entries ?? []),
                fn ($row) => is_array($row) && filled($row['on'] ?? null) && filled($row['title'] ?? null),
            ),
        ));
    }

    /** Keyed by date, which is how the planner looks things up. */
    public function entryFor(string $date): ?array
    {
        foreach ($this->entryList() as $entry) {
            if ($entry['on'] === $date) {
                return $entry;
            }
        }

        return null;
    }
}
