<?php

namespace App\Services\Meals;

use App\Models\Household;
use App\Models\Meal;
use App\Models\Recipe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Picks something for a night nobody has decided about.
 *
 * The whole point is to be *ignorable*: nothing here writes, and every
 * suggestion is a proposal the planner shows until somebody accepts it. A
 * planner that filled itself in would be a planner the family stopped
 * trusting, because the one thing worse than an empty Thursday is a Thursday
 * that says something wrong.
 */
class MealSuggester
{
    /**
     * What each day tends to want.
     *
     * A preference, never a rule: Friday leans fakeaway and Sunday leans
     * towards something slow, but a week where nothing carries the right tag
     * still gets filled. Keyed by ISO day, 1 = Monday.
     *
     * @var array<int, list<string>>
     */
    public const DAY_LEANINGS = [
        1 => ['weeknight', 'quick'],
        2 => ['weeknight', 'quick'],
        3 => ['weeknight', 'quick'],
        4 => ['weeknight', 'batch'],
        5 => ['fakeaway'],
        6 => [],
        7 => ['weekend', 'batch'],
    ];

    /**
     * One idea for one empty night: well liked, and not had lately.
     *
     * @param  list<int>  $avoid  ideas already proposed elsewhere this week
     */
    public function surprise(Household $household, CarbonImmutable $date, array $avoid = []): ?Recipe
    {
        $pool = $this->pool($household, $avoid);

        if ($pool->isEmpty()) {
            return null;
        }

        // The day's leaning first, then anything. Shuffled rather than ordered,
        // because "surprise me" twice in a row should not offer the same thing.
        return $this->leaningTowards($pool, $date)->shuffle()->first()
            ?? $pool->shuffle()->first();
    }

    /**
     * A whole week, proposed but not written.
     *
     * Only the empty nights: a plan somebody has already made is not a gap.
     *
     * @return Collection<string, Recipe> keyed by date
     */
    public function week(Household $household, CarbonImmutable $weekStart, string $slot = 'dinner'): Collection
    {
        $planned = Meal::query()
            ->where('household_id', $household->id)
            ->where('slot', $slot)
            ->whereBetween('on', [$weekStart->toDateString(), $weekStart->addDays(6)->toDateString()])
            ->pluck('on')
            ->map(fn ($on) => CarbonImmutable::parse($on)->toDateString())
            ->all();

        $pool = $this->pool($household);
        $chosen = collect();
        $today = $household->todayLocal();

        for ($day = $weekStart; $day->lessThan($weekStart->addDays(7)); $day = $day->addDay()) {
            // Wednesday is no time to be told what to have on Monday. Filling
            // the current week means filling the rest of it.
            if ($day->lessThan($today) || in_array($day->toDateString(), $planned, true)) {
                continue;
            }

            // Taken out of the pool as it goes, so a week is seven different
            // dinners rather than the same favourite four times.
            $pick = $this->leaningTowards($pool, $day)->shuffle()->first() ?? $pool->shuffle()->first();

            if (! $pick) {
                break;
            }

            $chosen->put($day->toDateString(), $pick);
            $pool = $pool->reject(fn (Recipe $r) => $r->id === $pick->id)->values();
        }

        return $chosen;
    }

    /**
     * The ideas worth proposing at all.
     *
     * Favourites and well-liked things that have had a rest. An idea nobody
     * has rated is still in — a box where only the scored things can be
     * suggested never suggests anything new.
     *
     * @param  list<int>  $avoid
     * @return Collection<int, Recipe>
     */
    protected function pool(Household $household, array $avoid = []): Collection
    {
        $today = $household->todayLocal();
        $rested = $today->subDays(Recipe::A_WHILE_DAYS)->toDateString();

        return Recipe::query()
            ->where('household_id', $household->id)
            ->ready()
            ->when($avoid !== [], fn ($q) => $q->whereNotIn('id', $avoid))
            ->withCookedHistory($today->toDateString())
            ->withOpinions()
            ->get()
            // Had in the last three weeks: give it a rest.
            ->reject(fn (Recipe $r) => $r->last_cooked_on !== null
                && CarbonImmutable::parse($r->last_cooked_on)->toDateString() > $rested)
            // Rated below four stars: somebody has already said no.
            ->reject(fn (Recipe $r) => $r->stars() !== null && $r->stars() < Recipe::WELL_LIKED)
            ->values();
    }

    /**
     * @param  Collection<int, Recipe>  $pool
     * @return Collection<int, Recipe>
     */
    protected function leaningTowards(Collection $pool, CarbonImmutable $date): Collection
    {
        $tags = self::DAY_LEANINGS[$date->dayOfWeekIso] ?? [];

        if ($tags === []) {
            return collect();
        }

        return $pool->filter(
            fn (Recipe $r) => collect($tags)->contains(fn (string $tag) => $r->hasTag($tag))
        )->values();
    }
}
