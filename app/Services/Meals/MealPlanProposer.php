<?php

namespace App\Services\Meals;

use App\Models\Household;
use App\Models\Meal;
use App\Models\MealPlanProposal;
use App\Models\Recipe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Turning "plan next week, one veggie night, quick on Tuesdays" into ghosts.
 *
 * This is the only thing in the assistant that writes a row, and the row it
 * writes is not a meal. That distinction is the whole design: the model can
 * put a suggestion in front of the family and cannot put dinner on the table.
 * Everything it proposes is drawn in ghost text in the planner until a
 * grown-up taps Keep, and the proposal is thrown away either way.
 *
 * The guards here are not politeness. A model asked for next week will
 * cheerfully return last Tuesday, seven copies of Wednesday, or a night the
 * family already decided about, and none of those should reach the planner.
 */
class MealPlanProposer
{
    /** A week is seven nights; anything more is a mistake, not a plan. */
    public const MAX_ENTRIES = 7;

    /** Weeks ahead a proposal may be made for. Beyond this nobody is planning. */
    public const MAX_WEEKS_AHEAD = 8;

    /**
     * Stage a proposed week.
     *
     * @param  list<array<string, mixed>>  $entries  as the model gave them
     * @return array{proposal: ?MealPlanProposal, kept: list<string>, skipped: list<string>}
     */
    public function propose(
        Household $household,
        CarbonImmutable $weekStart,
        array $entries,
        string $slot = 'dinner',
        ?string $askedFor = null,
        ?string $note = null,
    ): array {
        $weekStart = $this->weekOf($household, $weekStart);
        $taken = $this->alreadyPlanned($household, $weekStart, $slot);
        $today = $household->todayLocal();

        $kept = [];
        $skipped = [];
        $seen = [];

        foreach ($entries as $row) {
            if (count($kept) >= self::MAX_ENTRIES) {
                break;
            }

            $on = $this->dayIn($weekStart, $row['on'] ?? null);

            if ($on === null) {
                $skipped[] = 'a night that is not in that week';

                continue;
            }

            $date = $on->toDateString();

            if (in_array($date, $seen, true)) {
                $skipped[] = $on->format('l').' (proposed twice)';

                continue;
            }

            // Wednesday is no time to be told what to have on Monday.
            if ($on->lessThan($today)) {
                $skipped[] = $on->format('l').' (already gone)';

                continue;
            }

            if (in_array($date, $taken, true)) {
                $skipped[] = $on->format('l').' (already planned)';

                continue;
            }

            $title = trim((string) ($row['title'] ?? ''));

            if ($title === '') {
                $skipped[] = $on->format('l').' (no idea given)';

                continue;
            }

            $seen[] = $date;

            $kept[] = [
                'on' => $date,
                'title' => mb_substr($title, 0, 120),
                'recipe_id' => $this->ownRecipeId($household, $row['recipe_id'] ?? null, $title),
                'why' => filled($row['why'] ?? null) ? mb_substr(trim((string) $row['why']), 0, 160) : null,
            ];
        }

        if ($kept === []) {
            $this->clear($household, $weekStart, $slot);

            return ['proposal' => null, 'kept' => [], 'skipped' => $skipped];
        }

        usort($kept, fn (array $a, array $b) => $a['on'] <=> $b['on']);

        $proposal = MealPlanProposal::updateOrCreate(
            [
                'household_id' => $household->id,
                'week_start' => $weekStart->toDateString(),
                'slot' => $slot,
            ],
            [
                'entries' => $kept,
                'asked_for' => $askedFor ? mb_substr($askedFor, 0, 500) : null,
                'note' => $note ? mb_substr($note, 0, 500) : null,
            ],
        );

        return ['proposal' => $proposal, 'kept' => array_column($kept, 'on'), 'skipped' => $skipped];
    }

    public function forWeek(Household $household, CarbonImmutable $weekStart, string $slot = 'dinner'): ?MealPlanProposal
    {
        return MealPlanProposal::query()->forWeek($household, $weekStart, $slot)->first();
    }

    public function clear(Household $household, CarbonImmutable $weekStart, string $slot = 'dinner'): void
    {
        MealPlanProposal::query()->forWeek($household, $weekStart, $slot)->delete();
    }

    /**
     * Accept one night: write the meal, and put a brand-new idea in the box.
     *
     * An idea the family did not already have becomes a recipe with nothing
     * but a title, exactly as typing it into Ideas would. That is the point of
     * proposing new things rather than only favourites — the box grows.
     */
    public function accept(Household $household, MealPlanProposal $proposal, string $date): ?Meal
    {
        $entry = $proposal->entryFor($date);

        if (! $entry) {
            return null;
        }

        $recipeId = $entry['recipe_id'];

        if ($recipeId === null) {
            $recipeId = $this->rememberIdea($household, $entry['title'])?->id;
        }

        $meal = Meal::updateOrCreate(
            ['household_id' => $household->id, 'on' => $date, 'slot' => $proposal->slot],
            ['title' => $entry['title'], 'recipe_id' => $recipeId],
        );

        $this->drop($proposal, $date);

        return $meal;
    }

    /** @return list<Meal> */
    public function acceptAll(Household $household, MealPlanProposal $proposal): array
    {
        $meals = [];

        foreach ($proposal->entryList() as $entry) {
            if ($meal = $this->accept($household, $proposal, $entry['on'])) {
                $meals[] = $meal;
            }
        }

        return $meals;
    }

    /**
     * The idea, saved as a recipe with nothing but a name.
     *
     * Matched on the title first: a family that already has "Fish pie" should
     * end up with one Fish pie, not a second one because the model capitalised
     * it differently.
     */
    protected function rememberIdea(Household $household, string $title): ?Recipe
    {
        $existing = Recipe::query()
            ->where('household_id', $household->id)
            ->whereRaw('lower(title) = ?', [mb_strtolower($title)])
            ->first();

        return $existing ?? Recipe::create([
            'household_id' => $household->id,
            'title' => $title,
            'source_kind' => 'suggested',
            'status' => 'ready',
        ]);
    }

    /** Take one night out; delete the proposal once there is nothing left in it. */
    protected function drop(MealPlanProposal $proposal, string $date): void
    {
        $left = array_values(array_filter(
            $proposal->entryList(),
            fn (array $entry) => $entry['on'] !== $date,
        ));

        if ($left === []) {
            $proposal->delete();

            return;
        }

        $proposal->forceFill(['entries' => $left])->save();
    }

    /**
     * The Monday of the week asked about, kept inside sensible bounds.
     *
     * A proposal for a week that has already gone is not worth storing, and
     * one for next spring is a model that misread the date.
     */
    protected function weekOf(Household $household, CarbonImmutable $asked): CarbonImmutable
    {
        $thisWeek = $household->weekStart();
        $asked = $asked->startOfDay();

        // Snap to the same weekday the household starts its week on.
        $weeks = (int) floor($thisWeek->diffInDays($asked, false) / 7);

        return $thisWeek->addWeeks(max(0, min($weeks, self::MAX_WEEKS_AHEAD)));
    }

    /** @return list<string> */
    protected function alreadyPlanned(Household $household, CarbonImmutable $weekStart, string $slot): array
    {
        return Meal::query()
            ->where('household_id', $household->id)
            ->where('slot', $slot)
            ->whereBetween('on', [$weekStart->toDateString(), $weekStart->addDays(6)->toDateString()])
            ->pluck('on')
            ->map(fn ($on) => CarbonImmutable::parse($on)->toDateString())
            ->all();
    }

    /** A date the model gave, only if it really is in the week it was asked about. */
    protected function dayIn(CarbonImmutable $weekStart, mixed $given): ?CarbonImmutable
    {
        if (! is_string($given) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($given))) {
            return null;
        }

        try {
            $day = CarbonImmutable::createFromFormat('Y-m-d', trim($given), $weekStart->timezone)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        return $day->betweenIncluded($weekStart, $weekStart->addDays(6)) ? $day : null;
    }

    /**
     * A recipe id, only if it is this household's.
     *
     * The model is given ids from the ideas tool, so a wrong one is a
     * misremembering rather than an attack — but a proposal that quietly
     * pointed at another family's recipe would be a bad way to find that out.
     */
    protected function ownRecipeId(Household $household, mixed $given, string $title): ?int
    {
        if (is_numeric($given)) {
            $recipe = Recipe::query()
                ->where('household_id', $household->id)
                ->find((int) $given);

            if ($recipe) {
                return $recipe->id;
            }
        }

        // No id, or a bad one: fall back to a title we already know, so
        // "Fish pie" lands on the family's own fish pie rather than a copy.
        return Recipe::query()
            ->where('household_id', $household->id)
            ->whereRaw('lower(title) = ?', [mb_strtolower(trim($title))])
            ->value('id');
    }

    /**
     * What the assistant is told back, so it can say what it did.
     *
     * @param  array{proposal: ?MealPlanProposal, kept: list<string>, skipped: list<string>}  $result
     */
    public function describe(Household $household, array $result, CarbonImmutable $weekStart): string
    {
        if ($result['proposal'] === null) {
            return 'Nothing could be proposed for the week of '.$weekStart->format('j F')
                .($result['skipped'] === [] ? '.' : ': '.implode(', ', array_unique($result['skipped'])).'.');
        }

        $lines = ['Proposed in the planner for the week of '.$weekStart->format('j F')
            .' — not saved, and waiting for somebody to accept it:'];

        foreach ($result['proposal']->entryList() as $entry) {
            $day = CarbonImmutable::parse($entry['on'], $household->displayTimezone());

            $lines[] = '- '.$day->format('l j M').': '.$entry['title']
                .($entry['why'] ? ' ('.$entry['why'].')' : '');
        }

        if ($result['skipped'] !== []) {
            $lines[] = 'Left alone: '.implode(', ', array_unique($result['skipped'])).'.';
        }

        return implode("\n", $lines);
    }

    /**
     * The recipe box as the model needs to see it to plan a week.
     *
     * Everything, not just the favourites: a planner that can only propose
     * what the family already loves proposes the same six dinners forever.
     * What the ratings do is stop it proposing the ones somebody has already
     * said no to.
     *
     * @return Collection<int, Recipe>
     */
    public function ideas(Household $household, int $limit = 60): Collection
    {
        return Recipe::query()
            ->where('household_id', $household->id)
            ->ready()
            ->withCookedHistory($household->todayLocal()->toDateString())
            ->withOpinions()
            ->orderByDesc('is_favourite')
            ->orderBy('title')
            ->limit($limit)
            ->get();
    }
}
