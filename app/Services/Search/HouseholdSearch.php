<?php

namespace App\Services\Search;

use App\Models\Capture;
use App\Models\CaptureItem;
use App\Models\ChecklistItem;
use App\Models\Chore;
use App\Models\Event;
use App\Models\Household;
use App\Models\Meal;
use App\Models\Member;
use App\Models\Place;
use App\Models\Recipe;
use App\Models\Reward;
use App\Models\Routine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * One box that looks everywhere.
 *
 * Plain LIKE across a dozen small tables, because this is one household's data
 * and the largest table in it has a few thousand rows. An index and a search
 * service would be machinery in place of a wall calendar.
 *
 * Each source is capped, so a query like "a" costs a fixed amount however much
 * has accumulated.
 */
class HouseholdSearch
{
    /** Per source. The screen cannot show more than a handful anyway. */
    public const PER_TYPE = 8;

    /** @return Collection<int, SearchResult> */
    public function search(string $raw, ?Household $household = null): Collection
    {
        $household ??= Household::current();
        $query = SearchQuery::parse($raw, $household->todayLocal());

        if ($query->isEmpty()) {
            return collect();
        }

        return collect([
            ...$this->events($query, $household),
            ...$this->checklistItems($query, $household),
            ...$this->chores($query, $household),
            ...$this->routines($query, $household),
            ...$this->meals($query, $household),
            ...$this->recipes($query, $household),
            ...$this->rewards($query, $household),
            ...$this->captures($query, $household),
            ...$this->people($query, $household),
        ])
            ->filter(fn (SearchResult $r) => $query->covers($r->date))
            ->map(fn (SearchResult $r) => $r->withScore($this->score($r, $query)))
            ->sortByDesc(fn (SearchResult $r) => $r->score)
            ->values();
    }

    /**
     * How well a result answers the question.
     *
     * A title that starts with what was typed beats one that merely contains
     * it, which beats a match buried in the notes. Dated things nudge ahead of
     * undated ones when a period was named, because that is what was asked.
     */
    protected function score(SearchResult $result, SearchQuery $query): int
    {
        $title = mb_strtolower($result->title);
        $snippet = mb_strtolower((string) $result->snippet);

        // One for turning up at all. The database has already decided this row
        // matched; without a floor, anything matched on a column that is not
        // displayed — a recipe's tags, the text a capture was read from —
        // scores nothing and is silently thrown away.
        $score = 1;

        foreach ($query->terms as $term) {
            if (str_starts_with($title, $term)) {
                $score += 6;
            } elseif (str_contains($title, $term)) {
                $score += 4;
            } elseif (str_contains($snippet, $term)) {
                $score += 2;
            }
        }

        // Soon beats long ago: a dentist appointment next week is more likely
        // to be the one meant than the one last spring.
        if ($result->date?->isFuture()) {
            $score += 2;
        }

        return $score;
    }

    /** @return list<SearchResult> */
    protected function events(SearchQuery $query, Household $household): array
    {
        $events = Event::query()
            ->whereHas('calendar.account', fn ($q) => $q->where('household_id', $household->id))
            ->where(function (Builder $q) use ($query) {
                $this->anyTerm($q, $query, ['title', 'location', 'notes']);
                $q->orWhereHas('members', fn ($m) => $this->anyTerm($m, $query, ['name']));
            })
            ->when($query->hasDates(), fn ($q) => $q
                ->where('start_at', '>=', $query->from->startOfDay())
                ->where('start_at', '<=', $query->to->endOfDay()))
            ->with('members')
            ->orderByDesc('start_at')
            ->limit(self::PER_TYPE)
            ->get();

        return $events->map(fn (Event $event) => new SearchResult(
            type: 'event',
            title: $event->title,
            snippet: $this->join([
                $event->members->pluck('name')->join(', ') ?: null,
                $event->location,
                $event->notes,
            ]),
            date: $event->start_at?->timezone($household->displayTimezone()),
            url: route('app', ['event' => $event->id]),
            opens: ['edit-event' => $event->id],
        ))->all();
    }

    /** To-dos and shopping, which are the same model wearing different hats. */
    /** @return list<SearchResult> */
    protected function checklistItems(SearchQuery $query, Household $household): array
    {
        $items = ChecklistItem::query()
            ->whereHas('checklist', fn ($q) => $q->where('household_id', $household->id))
            ->where(fn (Builder $q) => $this->anyTerm($q, $query, ['title', 'notes', 'quantity']))
            ->with('checklist')
            ->limit(self::PER_TYPE * 2)
            ->get();

        return $items->map(function (ChecklistItem $item) {
            $shopping = $item->checklist?->type === 'shopping';

            return new SearchResult(
                type: $shopping ? 'shopping' : 'todo',
                title: $item->title,
                snippet: $this->join([
                    $item->checklist?->name,
                    $item->quantity,
                    $item->is_done ? 'done' : null,
                ]),
                date: $item->due_on,
                url: $shopping ? route('shopping') : route('app'),
            );
        })->all();
    }

    /** @return list<SearchResult> */
    protected function chores(SearchQuery $query, Household $household): array
    {
        return Chore::query()
            ->where('household_id', $household->id)
            ->where(fn (Builder $q) => $this->anyTerm($q, $query, ['title']))
            ->with('member')
            ->limit(self::PER_TYPE)
            ->get()
            ->map(fn (Chore $chore) => new SearchResult(
                type: 'chore',
                title: $chore->title,
                snippet: $this->join([$chore->member?->name, $chore->scheduleLabel()]),
                url: route('admin.chores'),
            ))->all();
    }

    /** @return list<SearchResult> */
    protected function routines(SearchQuery $query, Household $household): array
    {
        return Routine::query()
            ->where('household_id', $household->id)
            ->where(function (Builder $q) use ($query) {
                $this->anyTerm($q, $query, ['name']);
                $q->orWhereHas('steps', fn ($s) => $this->anyTerm($s, $query, ['title']));
            })
            ->with(['member', 'steps'])
            ->limit(self::PER_TYPE)
            ->get()
            ->map(fn (Routine $routine) => new SearchResult(
                type: 'routine',
                title: $routine->label(),
                snippet: $this->join([
                    $routine->member?->name,
                    $routine->steps->pluck('title')->join(', ') ?: null,
                ]),
                url: route('admin.routines'),
            ))->all();
    }

    /** @return list<SearchResult> */
    protected function meals(SearchQuery $query, Household $household): array
    {
        return Meal::query()
            ->where('household_id', $household->id)
            ->where(fn (Builder $q) => $this->anyTerm($q, $query, ['title']))
            ->when($query->hasDates(), fn ($q) => $q
                ->whereBetween('on', [$query->from->toDateString(), $query->to->toDateString()]))
            ->limit(self::PER_TYPE)
            ->get()
            ->map(fn (Meal $meal) => new SearchResult(
                type: 'meal',
                title: $meal->title,
                snippet: ucfirst($meal->slot),
                date: $meal->on,
                url: route('meals'),
            ))->all();
    }

    /** @return list<SearchResult> */
    protected function recipes(SearchQuery $query, Household $household): array
    {
        // Ingredients and tags are JSON, so they are matched as text — which
        // is exactly what a LIKE over a small table is good for.
        return Recipe::query()
            ->where('household_id', $household->id)
            ->where(fn (Builder $q) => $this->anyTerm($q, $query, ['title', 'ingredients', 'tags', 'source_note']))
            ->limit(self::PER_TYPE)
            ->get()
            ->map(fn (Recipe $recipe) => new SearchResult(
                type: 'recipe',
                title: $recipe->title,
                snippet: $this->join([
                    $recipe->summary() ?: null,
                    collect($recipe->tags ?? [])->join(', ') ?: null,
                    collect($recipe->ingredientList())->pluck('item')->take(4)->join(', ') ?: null,
                ]),
                url: route('recipes', ['recipe' => $recipe->id]),
                opens: ['show-recipe' => $recipe->id],
            ))->all();
    }

    /** @return list<SearchResult> */
    protected function rewards(SearchQuery $query, Household $household): array
    {
        return Reward::query()
            ->where('household_id', $household->id)
            ->where(fn (Builder $q) => $this->anyTerm($q, $query, ['name']))
            ->limit(self::PER_TYPE)
            ->get()
            ->map(fn (Reward $reward) => new SearchResult(
                type: 'reward',
                title: $reward->name,
                snippet: $reward->cost.' points',
                url: route('admin.rewards'),
            ))->all();
    }

    /** @return list<SearchResult> */
    protected function captures(SearchQuery $query, Household $household): array
    {
        $items = CaptureItem::query()
            ->whereHas('capture', fn ($q) => $q->where('household_id', $household->id))
            ->where(function (Builder $q) use ($query) {
                $this->anyTerm($q, $query, ['title', 'notes', 'excerpt', 'member_hint']);
                // The text it was read out of, not only what came back.
                $q->orWhereHas('capture', fn ($c) => $this->anyTerm($c, $query, ['subject', 'body_text']));
            })
            ->with('capture')
            ->limit(self::PER_TYPE)
            ->get();

        return $items->map(fn (CaptureItem $item) => new SearchResult(
            type: 'capture',
            title: $item->title,
            snippet: $this->join([
                ucfirst($item->status),
                $item->excerpt ?: $item->notes,
                // The source, so a hit found in it is visible rather than
                // looking like the wrong answer.
                $item->capture?->subject,
            ]),
            date: $item->start_at?->timezone($household->displayTimezone()),
            url: route('review'),
        ))->all();
    }

    /** @return list<SearchResult> */
    protected function people(SearchQuery $query, Household $household): array
    {
        $members = Member::query()
            ->where('household_id', $household->id)
            ->where(function (Builder $q) use ($query) {
                $this->anyTerm($q, $query, ['name']);
                $q->orWhereHas('aliases', fn ($a) => $this->anyTerm($a, $query, ['alias']));
            })
            ->limit(self::PER_TYPE)
            ->get()
            ->map(fn (Member $member) => new SearchResult(
                type: 'member',
                title: $member->name,
                snippet: $member->is_child ? 'Child' : 'Adult',
                url: route('admin'),
            ))->all();

        $places = Place::query()
            ->where('household_id', $household->id)
            ->where(function (Builder $q) use ($query) {
                $this->anyTerm($q, $query, ['name']);
                $q->orWhereHas('aliases', fn ($a) => $this->anyTerm($a, $query, ['alias']));
            })
            ->with('members')
            ->limit(self::PER_TYPE)
            ->get()
            ->map(fn (Place $place) => new SearchResult(
                type: 'place',
                title: $place->name,
                snippet: $this->join([$place->typeLabel(), $place->members->pluck('name')->join(', ') ?: null]),
                url: route('admin.places'),
            ))->all();

        return [...$members, ...$places];
    }

    /**
     * Match any of the words against any of the columns.
     *
     * @param  Builder<*>  $query
     * @param  list<string>  $columns
     */
    protected function anyTerm(Builder $query, SearchQuery $search, array $columns): void
    {
        if ($search->terms === []) {
            // A bare period matches everything, and the range does the work.
            $query->whereRaw('1 = 1');

            return;
        }

        $query->where(function (Builder $q) use ($search, $columns) {
            foreach ($search->terms as $term) {
                foreach ($columns as $column) {
                    // whereRaw, because Laravel's like helper cannot attach an
                    // ESCAPE clause — and escaping without one turns "50%"
                    // into a search for a literal backslash.
                    $q->orWhereRaw(
                        $q->getGrammar()->wrap($column)." like ? escape '\\'",
                        ['%'.$this->escape($term).'%'],
                    );
                }
            }
        });
    }

    /** A search for "50%" should not match everything. */
    protected function escape(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }

    /** @param list<?string> $parts */
    protected function join(array $parts): ?string
    {
        $text = collect($parts)->filter()->map(fn ($p) => trim(preg_replace('/\s+/u', ' ', (string) $p)))->join(' · ');

        return $text === '' ? null : mb_substr($text, 0, 160);
    }
}
