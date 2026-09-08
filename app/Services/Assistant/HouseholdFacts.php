<?php

namespace App\Services\Assistant;

use App\Models\BinCollection;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Event;
use App\Models\Household;
use App\Models\Meal;
use App\Models\Member;
use App\Models\Recipe;
use App\Services\Chores\ChoreBoard;
use App\Services\Chores\ChoreSlot;
use App\Services\Points\PointsLedger;
use App\Services\Schools\SchoolCalendar;
use App\Services\Schools\SchoolClosure;
use App\Services\Search\HouseholdSearch;
use App\Services\Search\SearchResult;
use Carbon\CarbonImmutable;

/**
 * The household, read out loud.
 *
 * Every method here answers one question in a few lines of plain text, and
 * every one of them is a read. That is the whole design: the assistant is
 * given these as tools rather than a database, so the worst a wrong answer can
 * do is be wrong — there is no path from here to a write, to iCloud, or to
 * anything the family would have to undo.
 *
 * Text rather than JSON because it is what the answer is made of: the model
 * quotes these lines back nearly verbatim, and every brace is a token spent
 * on nothing.
 */
class HouseholdFacts
{
    /** A range longer than this is a mistake, not a question. */
    public const MAX_DAYS = 180;

    /** Per section. Enough to answer with; not enough to drown in. */
    public const LIMIT = 40;

    public function __construct(
        protected HouseholdSearch $search,
        protected ChoreBoard $chores,
        protected PointsLedger $ledger,
        protected SchoolCalendar $schools,
    ) {}

    /** Anything, anywhere — the fallback when no other tool fits. */
    public function search(Household $household, string $query): string
    {
        $results = $this->search->search($query, $household)->take(self::LIMIT);

        if ($results->isEmpty()) {
            return 'Nothing in the household matches "'.$query.'".';
        }

        $lines = ['Search results for "'.$query.'":'];

        foreach ($results->groupBy(fn (SearchResult $r) => $r->group()) as $group => $found) {
            $lines[] = '';
            $lines[] = $group.':';

            foreach ($found as $result) {
                $lines[] = '- '.$result->title
                    .($result->date ? ' — '.$this->day($household, $result->date) : '')
                    .($result->snippet ? ' ('.$result->snippet.')' : '');
            }
        }

        // Search has no floor: it reaches back over everything the household
        // has ever had. Worth saying, because a match from last June looks
        // exactly like a match from next June once it is a line of text.
        $lines[] = '';
        $lines[] = 'These come from the whole household, past and future alike. '
            .'Check each date before treating one as a plan.';

        return implode("\n", $lines);
    }

    /** What is on, between two dates. The answer to most questions asked here. */
    public function calendar(Household $household, CarbonImmutable $from, CarbonImmutable $to, ?string $member = null): string
    {
        $tz = $household->displayTimezone();

        $events = Event::query()
            ->notCancelled()
            ->overlapping($from->startOfDay(), $to->endOfDay())
            ->whereHas('calendar', fn ($q) => $q
                ->where('is_visible', true)
                ->whereHas('account', fn ($a) => $a->where('household_id', $household->id)))
            ->with(['calendar.member', 'members'])
            ->orderBy('start_at')
            ->limit(self::LIMIT * 3)
            ->get();

        if ($member !== null) {
            $events = $events->filter(fn (Event $e) => $this->concerns($e, $member))->values();
        }

        if ($events->isEmpty()) {
            return 'Nothing in the calendar '.$this->between($household, $from, $to)
                .($member ? ' for '.$member : '').'.';
        }

        $lines = ['Calendar, '.$this->between($household, $from, $to).($member ? ', for '.$member : '').':'];

        foreach ($events->take(self::LIMIT) as $event) {
            $start = $event->start_at->timezone($tz);
            // Whose calendar it is, or failing that what the calendar is
            // called: this household keeps one shared "Family" calendar with
            // no owner, and a citation of "" is no citation at all.
            $whose = $event->calendar?->member?->name ?? $event->calendar?->name;
            $people = $event->members->pluck('name')->all();

            $lines[] = '- '.$this->day($household, CarbonImmutable::parse($start))
                .' '.($event->all_day ? 'all day' : $start->format('H:i'))
                .': '.$event->title
                .($event->location ? ' at '.$event->location : '')
                .($whose ? ' — from the '.$whose.' calendar' : '')
                .($people === [] ? '' : ' (with '.implode(', ', $people).')');
        }

        if ($events->count() > self::LIMIT) {
            $lines[] = '(and '.($events->count() - self::LIMIT).' more — narrow the dates)';
        }

        return implode("\n", $lines);
    }

    /** The meal plan, one line per planned meal. */
    public function meals(Household $household, CarbonImmutable $from, CarbonImmutable $to): string
    {
        $meals = Meal::query()
            ->where('household_id', $household->id)
            ->between($from->toDateString(), $to->toDateString())
            ->orderBy('on')
            ->get();

        if ($meals->isEmpty()) {
            return 'Nothing is planned in the meal plan '.$this->between($household, $from, $to).'.';
        }

        $slots = array_flip($household->mealSlots());

        $lines = ['From the meal plan, '.$this->between($household, $from, $to).':'];

        foreach ($meals->sortBy([['on', 'asc'], fn ($a, $b) => ($slots[$a->slot] ?? 9) <=> ($slots[$b->slot] ?? 9)]) as $meal) {
            $lines[] = '- '.$this->day($household, CarbonImmutable::parse($meal->on))
                .' '.$meal->slot.': '.$meal->title;
        }

        return implode("\n", $lines);
    }

    /** Chores due in a range, and what has happened to each. */
    public function chores(Household $household, CarbonImmutable $from, CarbonImmutable $to, ?string $member = null): string
    {
        $days = $this->chores->forRange($household, $from, $to);
        $lines = [];

        foreach ($days as $date => $byMember) {
            $slots = $byMember->flatten(1)
                ->filter(fn (ChoreSlot $slot) => $member === null || $this->named($slot->chore->member?->name, $member));

            if ($slots->isEmpty()) {
                continue;
            }

            $lines[] = '';
            $lines[] = $this->day($household, CarbonImmutable::parse($date)).':';

            foreach ($slots as $slot) {
                $lines[] = '- '.$slot->chore->title
                    .' — '.($slot->chore->member?->name ?? 'anyone')
                    .', '.$this->choreState($slot);
            }
        }

        if ($lines === []) {
            return 'No chores are due '.$this->between($household, $from, $to).($member ? ' for '.$member : '').'.';
        }

        return 'From the chore board, '.$this->between($household, $from, $to).":\n".implode("\n", $lines);
    }

    protected function choreState(ChoreSlot $slot): string
    {
        return match (true) {
            $slot->isApproved() => 'done and approved, '.$slot->points().' points',
            $slot->isAwaitingApproval() => 'done, waiting for a grown-up to approve it',
            $slot->isDone() => 'done, '.$slot->points().' points',
            default => 'not done yet',
        };
    }

    /** To-dos and the shopping list. */
    public function lists(Household $household, string $kind = 'all', bool $includeDone = false): string
    {
        $types = match ($kind) {
            'todo' => ['todo'],
            'shopping' => ['shopping'],
            default => ['todo', 'shopping'],
        };

        $lists = Checklist::query()
            ->where('household_id', $household->id)
            ->whereIn('type', $types)
            ->orderBy('sort_order')
            ->get();

        $today = $household->todayLocal();
        $lines = [];

        foreach ($lists as $list) {
            $items = $list->items()
                ->when(! $includeDone, fn ($q) => $q->where('is_done', false))
                ->inDueOrder()
                ->limit(self::LIMIT)
                ->with('member')
                ->get();

            $lines[] = '';
            $lines[] = 'From the '.$list->name.' list ('.$list->type.'):';

            if ($items->isEmpty()) {
                $lines[] = '- nothing outstanding';

                continue;
            }

            foreach ($items as $item) {
                $lines[] = '- '.$item->title
                    .($item->quantity ? ' ('.$item->quantity.')' : '')
                    .($item->due_on ? ' — due '.$this->day($household, CarbonImmutable::parse($item->due_on)) : '')
                    .($item->member?->name ? ' — '.$item->member->name : '')
                    .($item->is_done ? ' [done]' : $this->overdue($item, $today));
            }
        }

        return $lines === [] ? 'There are no lists set up.' : trim(implode("\n", $lines));
    }

    protected function overdue(ChecklistItem $item, CarbonImmutable $today): string
    {
        return $item->isOverdue($today) ? ' [overdue]' : '';
    }

    /** The next few bin collections. */
    public function bins(Household $household, int $weeks = 4): string
    {
        $today = $household->todayLocal();

        $collections = BinCollection::query()
            ->where('household_id', $household->id)
            ->upcoming($today->toDateString())
            ->where('on', '<=', $today->addWeeks(max(1, min($weeks, 12)))->toDateString())
            ->orderBy('on')
            ->get();

        if ($collections->isEmpty()) {
            return 'No bin collections are known for the next '.$weeks.' weeks.';
        }

        $lines = ['From the bin schedule:'];

        foreach ($collections->groupBy(fn (BinCollection $c) => $c->on->toDateString()) as $date => $onDay) {
            $lines[] = '- '.$this->day($household, CarbonImmutable::parse($date)).': '
                .$onDay->map(fn (BinCollection $c) => $c->label())->implode(', ');
        }

        return implode("\n", $lines);
    }

    /** School holidays, INSET days, bank holidays and the days either side. */
    public function schoolDates(Household $household, CarbonImmutable $from, CarbonImmutable $to): string
    {
        $closures = $this->schools->closures($household, $from, $to);
        $turning = $this->schools->turningPoints($household, $household->todayLocal(), withinDays: 14);

        if ($closures->isEmpty() && $turning->isEmpty()) {
            return 'No school closures or term boundaries '.$this->between($household, $from, $to).'.';
        }

        $lines = ['From the school term dates, '.$this->between($household, $from, $to).':'];

        foreach ($closures->take(self::LIMIT) as $closure) {
            /** @var SchoolClosure $closure */
            $lines[] = '- '.$closure->label.' ('.$closure->code.'): '
                .($closure->startsOn->isSameDay($closure->endsOn)
                    ? $this->day($household, $closure->startsOn)
                    : $this->day($household, $closure->startsOn).' to '.$this->day($household, $closure->endsOn));
        }

        foreach ($turning as $point) {
            $lines[] = '- '.$point['label'].' ('.$point['code'].'): '.$this->day($household, $point['on'])
                .($point['finishes'] ? ', finishes '.$point['finishes'] : '');
        }

        return implode("\n", $lines);
    }

    /** Points balances, and anything waiting on a grown-up. */
    public function points(Household $household, ?string $member = null): string
    {
        $children = $household->members()->children()->get()
            ->filter(fn (Member $m) => $member === null || $this->named($m->name, $member));

        if ($children->isEmpty()) {
            return $member ? 'There is no child called '.$member.'.' : 'There are no children in the household.';
        }

        $waiting = $this->chores->awaitingApproval($household);
        $lines = ['From the points ledger:'];

        foreach ($children as $child) {
            $pending = $waiting->where('member_id', $child->id);

            $lines[] = '- '.$child->name.': '.$this->ledger->balanceFor($child).' points'
                .($pending->isEmpty() ? '' : ', plus '.$pending->sum('points')
                    .' waiting for a grown-up to approve');
        }

        return implode("\n", $lines);
    }

    /** One saved recipe, with what is in it. */
    public function recipe(Household $household, string $name): string
    {
        $recipe = Recipe::query()
            ->where('household_id', $household->id)
            ->where('title', 'like', '%'.str_replace(['%', '_', HouseholdSearch::ESCAPE], '', $name).'%')
            ->orderByRaw('length(title)')
            ->first();

        if (! $recipe) {
            return 'There is no saved recipe matching "'.$name.'".';
        }

        $lines = ['From the recipe box — '.$recipe->title.':'];

        if ($recipe->servings) {
            $lines[] = 'Serves '.$recipe->servings.'.';
        }

        if ($recipe->hasIngredients()) {
            $lines[] = 'Ingredients:';

            foreach ($recipe->ingredientList() as $row) {
                $lines[] = '- '.trim(($row['quantity'] ?? '').' '.($row['unit'] ?? '').' '.$row['item']);
            }
        }

        foreach ((array) ($recipe->steps ?? []) as $i => $step) {
            if ($i === 0) {
                $lines[] = 'Method:';
            }

            $lines[] = ($i + 1).'. '.(is_string($step) ? $step : ($step['text'] ?? ''));
        }

        if ($label = $recipe->sourceLabel()) {
            $lines[] = 'From '.$label.'.';
        }

        return implode("\n", $lines);
    }

    /**
     * A date the model asked for, or a sensible one.
     *
     * The model is told today's date and asked for YYYY-MM-DD, but a wrong
     * date should give an unhelpful answer, not a 500 on the family's phone.
     */
    public function date(Household $household, mixed $given, ?CarbonImmutable $fallback = null): CarbonImmutable
    {
        $fallback ??= $household->todayLocal();

        if (! is_string($given) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($given))) {
            return $fallback;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', trim($given), $household->displayTimezone())->startOfDay();
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /** Keeps one question from asking for a decade of calendar. */
    public function clamp(CarbonImmutable $from, CarbonImmutable $to): CarbonImmutable
    {
        return $to->lessThan($from) ? $from : $to->min($from->addDays(self::MAX_DAYS));
    }

    protected function between(Household $household, CarbonImmutable $from, CarbonImmutable $to): string
    {
        return $from->isSameDay($to)
            ? 'on '.$this->day($household, $from)
            : 'from '.$this->day($household, $from).' to '.$this->day($household, $to);
    }

    /**
     * A date, and whether it has already happened.
     *
     * The marker is the whole point. Asked when Joey has kickboxing, the model
     * was handed "Fri 19 Jun 2026" for a trip three months gone and answered
     * as though it were a plan — because nothing in the line said otherwise
     * and a bare date reads as an upcoming one.
     */
    protected function day(Household $household, CarbonImmutable $date): string
    {
        $written = $date->format('D j M Y');
        $today = $household->todayLocal();

        return match (true) {
            $date->lessThan($today) => $written.' (in the past)',
            $date->isSameDay($today) => $written.' (today)',
            default => $written,
        };
    }

    /** Loose enough for "joey", "Joey W" and an alias to all land. */
    protected function named(?string $actual, string $asked): bool
    {
        if ($actual === null) {
            return false;
        }

        $actual = mb_strtolower($actual);
        $asked = mb_strtolower(trim($asked));

        return $actual === $asked || str_contains($actual, $asked) || str_contains($asked, $actual);
    }

    protected function concerns(Event $event, string $member): bool
    {
        return $this->named($event->calendar?->member?->name, $member)
            || $event->members->contains(fn (Member $m) => $this->named($m->name, $member));
    }
}
