<?php

namespace App\Services\Summary;

use App\Models\ChoreInstance;
use App\Models\Event;
use App\Models\Household;
use App\Models\Meal;
use App\Models\Member;
use App\Models\PointEntry;
use App\Models\RecipeRating;
use App\Services\Countdowns\CountdownBoard;
use App\Services\Points\PointsLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The week, in one screen, on a Sunday evening.
 *
 * Deliberately a look backwards and one line forwards, not a report. The
 * things a family actually says to each other on a Sunday night are "who did
 * their jobs", "how many stars have you got now", "what did we eat" and
 * "what's on this week" — so those are the four sections and there is not a
 * fifth.
 *
 * Everything here is derived. Nothing about the summary is stored, which is
 * why it can be looked at again on Tuesday and still be right about Sunday.
 */
class WeeklySummary
{
    /** Sunday evening, once the day is effectively over. */
    public const HOUR = 18;

    public function __construct(
        protected PointsLedger $ledger,
        protected CountdownBoard $countdowns,
    ) {}

    /**
     * Whether it is the moment to put this in front of the household.
     *
     * The hour rather than the minute, because the caller runs every minute
     * and the ledger of what has already been sent is what stops it being
     * announced sixty times.
     */
    public function isDue(Household $household, ?CarbonImmutable $now = null): bool
    {
        $now ??= $household->nowLocal();

        // isSunday() rather than a day constant: Carbon's SUNDAY is 0 and
        // dayOfWeekIso's is 7, and comparing the two silently never fires.
        return $now->isSunday() && (int) $now->format('G') === self::HOUR;
    }

    /** The week being summarised: the one ending today, or the last complete one. */
    public function weekOf(Household $household, ?CarbonImmutable $on = null): CarbonImmutable
    {
        $on ??= $household->todayLocal();

        return $household->weekStart($on);
    }

    public function for(Household $household, ?CarbonImmutable $weekStart = null): SummaryWeek
    {
        $weekStart = $weekStart ?? $this->weekOf($household);
        $weekEnd = $weekStart->addDays(6);

        return new SummaryWeek(
            weekStart: $weekStart,
            weekEnd: $weekEnd,
            children: $this->children($household, $weekStart, $weekEnd),
            meals: $this->meals($household, $weekStart, $weekEnd),
            ahead: $this->ahead($household, $weekStart->addDays(7)),
        );
    }

    /**
     * What each child did, and what it came to.
     *
     * Children with nothing at all are still listed. A summary that quietly
     * dropped the one who did no jobs would be a summary that only ever said
     * nice things, and the family can see that for themselves.
     *
     * @return Collection<int, SummaryChild>
     */
    protected function children(Household $household, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $members = Member::query()
            ->where('household_id', $household->id)
            ->children()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        if ($members->isEmpty()) {
            return collect();
        }

        // Two grouped queries rather than two per child: a household of four
        // is not a performance problem, but the shape of the thing matters.
        // Not-null completed_at, not merely the row: un-ticking a chore leaves
        // the instance behind, and a summary that counted rows would credit a
        // job somebody took back.
        $done = ChoreInstance::query()
            ->whereBetween('on', [$from->toDateString(), $to->toDateString()])
            ->whereIn('member_id', $members->pluck('id'))
            ->whereNotNull('completed_at')
            ->get()
            ->groupBy('member_id');

        $spent = PointEntry::query()
            ->whereIn('member_id', $members->pluck('id'))
            ->where('kind', 'redemption')
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->get()
            ->groupBy('member_id');

        return $members->map(function (Member $member) use ($done, $spent, $from, $to) {
            $instances = $done->get($member->id) ?? collect();

            return new SummaryChild(
                member: $member,
                choresDone: $instances->count(),
                choresWaiting: $instances->filter(fn (ChoreInstance $i) => $i->approved_at === null)->count(),
                earned: $this->ledger->earnedBetween($member, $from->startOfDay()->toDateTimeString(), $to->endOfDay()->toDateTimeString()),
                // Stored negative; shown as what it cost.
                spent: abs((int) ($spent->get($member->id)?->sum('points') ?? 0)),
                balance: $this->ledger->balanceFor($member),
            );
        })->values();
    }

    /**
     * What was eaten, and what anyone said about it.
     *
     * Only what actually happened: a Thursday that was planned and then not
     * cooked is a planner entry, and pretending otherwise would make the
     * ratings meaningless.
     *
     * @return Collection<int, SummaryMeal>
     */
    protected function meals(Household $household, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $meals = Meal::query()
            ->where('household_id', $household->id)
            ->whereBetween('on', [$from->toDateString(), $to->toDateString()])
            ->where('slot', 'dinner')
            ->with('recipe')
            ->orderBy('on')
            ->get();

        if ($meals->isEmpty()) {
            return collect();
        }

        // Only ratings given during the week, so last month's opinion of the
        // fish pie does not turn up as this week's news.
        $ratings = RecipeRating::query()
            ->whereIn('recipe_id', $meals->pluck('recipe_id')->filter()->unique())
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->get()
            ->groupBy('recipe_id');

        return $meals->map(function (Meal $meal) use ($household, $ratings) {
            $given = $meal->recipe_id ? ($ratings->get($meal->recipe_id) ?? collect()) : collect();
            $stars = $given->whereNotNull('stars');

            return new SummaryMeal(
                on: CarbonImmutable::parse($meal->on, $household->displayTimezone()),
                title: $meal->title,
                stars: $stars->isEmpty() ? null : round((float) $stars->avg('stars'), 1),
                thumbsUp: $given->where('thumbs', '>', 0)->count(),
                thumbsDown: $given->where('thumbs', '<', 0)->count(),
            );
        })->values();
    }

    /**
     * The one line forwards.
     *
     * Not next week's whole calendar — that is what the calendar is for. The
     * handful of things somebody would want to know on a Sunday night so that
     * Monday morning is not a surprise.
     */
    protected function ahead(Household $household, CarbonImmutable $weekStart): SummaryAhead
    {
        $tz = $household->displayTimezone();
        $end = $weekStart->addDays(6);

        $events = Event::query()
            ->notCancelled()
            ->overlapping($weekStart->startOfDay(), $end->endOfDay())
            ->whereHas('calendar', fn ($q) => $q
                ->where('is_visible', true)
                ->whereHas('account', fn ($a) => $a->where('household_id', $household->id)))
            ->with('calendar.member')
            ->orderBy('start_at')
            ->limit(200)
            ->get();

        $planned = Meal::query()
            ->where('household_id', $household->id)
            ->where('slot', 'dinner')
            ->whereBetween('on', [$weekStart->toDateString(), $end->toDateString()])
            ->count();

        return new SummaryAhead(
            weekStart: $weekStart,
            eventCount: $events->count(),
            highlights: $events->take(SummaryAhead::HIGHLIGHTS)
                ->map(fn (Event $event) => [
                    'when' => CarbonImmutable::parse($event->start_at)->timezone($tz),
                    'title' => $event->title,
                    'all_day' => (bool) $event->all_day,
                ])
                ->values()
                ->toBase()
                ->all(),
            mealsPlanned: $planned,
            emptyNights: max(0, 7 - $planned),
            countdowns: $this->countdowns->upcoming($household, 2),
        );
    }
}
