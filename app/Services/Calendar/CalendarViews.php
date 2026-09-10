<?php

namespace App\Services\Calendar;

use App\Models\BinCollection;
use App\Models\Event;
use App\Models\Household;
use App\Services\Schools\SchoolCalendar;
use App\Services\Schools\SchoolClosure;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A month at a glance, and a day hour by hour.
 *
 * Shared between the wall and the phone because they are two windows onto the
 * same month: a month grid that disagreed with itself depending on which
 * screen you were standing at would be worse than having only one of them.
 *
 * The bands the household lives by — a school holiday, an INSET day, a bank
 * holiday, a bin day — are carried through every view rather than left behind
 * on the week grid, because "is that a school day?" is a question people ask
 * of a month, not only of a week.
 */
class CalendarViews
{
    /** Where a day view starts and ends when nothing is on outside it. */
    public const DAY_FROM = 7;

    public const DAY_TO = 22;

    public function __construct(protected SchoolCalendar $schools) {}

    /**
     * A month, as rows of seven days.
     *
     * Always six rows, so the grid does not change height from month to month
     * and the wall does not reflow around it.
     *
     * @return array{
     *     anchor: CarbonImmutable,
     *     weeks: list<list<array<string, mixed>>>,
     * }
     */
    public function month(Household $household, CarbonImmutable $anchor): array
    {
        $today = $household->todayLocal();
        $first = $anchor->startOfMonth();

        // Whole weeks, Monday first, so the grid lines up with the week view.
        $from = $first->startOfWeek(CarbonImmutable::MONDAY);
        $to = $from->addDays(41)->endOfDay();

        $events = $this->events($household, $from, $to);
        $closures = $this->schools->closures($household, $from, $to->startOfDay());
        $bins = $this->bins($household, $from, $to);

        $weeks = [];

        for ($week = 0; $week < 6; $week++) {
            $row = [];

            for ($offset = 0; $offset < 7; $offset++) {
                $day = $from->addDays($week * 7 + $offset);
                $onThisDay = $this->onDay($events, $day);

                $row[] = [
                    'date' => $day->toDateString(),
                    'carbon' => $day,
                    'number' => $day->day,
                    'in_month' => $day->month === $first->month,
                    'is_today' => $day->isSameDay($today),
                    'is_past' => $day->lessThan($today),
                    'events' => $onThisDay,
                    'count' => $onThisDay->count(),
                    // Distinct colours rather than one dot per event: a day
                    // with four dentist appointments is still one busy day.
                    'colours' => $this->coloursFor($onThisDay),
                    'closures' => $closures->filter(fn (SchoolClosure $c) => $c->covers($day))->values(),
                    'bins' => $bins->get($day->toDateString(), collect()),
                ];
            }

            $weeks[] = $row;
        }

        return ['anchor' => $first, 'weeks' => $weeks];
    }

    /**
     * One day, hour by hour, with the all-day things above it.
     *
     * The hour range is grown to fit whatever is actually on: a 6am flight and
     * an 11pm pick-up both have to be on the screen, and a fixed midnight to
     * midnight would waste two thirds of a wall on empty night.
     *
     * @return array{
     *     date: CarbonImmutable,
     *     from: int,
     *     to: int,
     *     hours: list<int>,
     *     all_day: Collection<int, Event>,
     *     timed: list<array<string, mixed>>,
     *     closures: Collection<int, SchoolClosure>,
     *     bins: Collection<int, BinCollection>,
     * }
     */
    public function day(Household $household, CarbonImmutable $date): array
    {
        $tz = $household->displayTimezone();
        $start = $date->startOfDay();
        $end = $date->endOfDay();

        $events = $this->events($household, $start, $end);
        $onThisDay = $this->onDay($events, $start);

        $allDay = $onThisDay->filter(fn (Event $e) => $e->all_day)->values();
        $timed = $onThisDay->reject(fn (Event $e) => $e->all_day)->values();

        [$from, $to] = $this->hourRange($timed, $tz);

        return [
            'date' => $date,
            'from' => $from,
            'to' => $to,
            'hours' => range($from, $to - 1),
            'all_day' => $allDay,
            'timed' => $timed->map(fn (Event $event) => $this->placed($event, $start, $tz, $from, $to))->all(),
            'closures' => $this->schools->closures($household, $start, $start)
                ->filter(fn (SchoolClosure $c) => $c->covers($start))->values(),
            'bins' => $this->bins($household, $start, $end)->get($date->toDateString(), collect()),
        ];
    }

    /**
     * Where an event sits on the timeline, as percentages of the visible day.
     *
     * Percentages rather than pixels: the same numbers place it on a phone and
     * on a wall, and neither has to know how tall the other's hours are.
     *
     * @return array<string, mixed>
     */
    protected function placed(Event $event, CarbonImmutable $start, string $tz, int $from, int $to): array
    {
        $minutes = max(1, ($to - $from) * 60);

        $begins = $event->start_at->timezone($tz);
        $ends = $event->end_at->timezone($tz);

        // Clipped to the day, so something that began yesterday starts at the
        // top rather than somewhere above the screen.
        $offset = max(0, ($begins->timestamp - $start->timestamp) / 60 - $from * 60);
        $length = max(15, ($ends->timestamp - $begins->timestamp) / 60);

        return [
            'event' => $event,
            'top' => round(min(100, $offset / $minutes * 100), 4),
            'height' => round(min(100 - $offset / $minutes * 100, $length / $minutes * 100), 4),
            'starts' => $begins,
            'ends' => $ends,
        ];
    }

    /**
     * @param  Collection<int, Event>  $timed
     * @return array{0: int, 1: int}
     */
    protected function hourRange(Collection $timed, string $tz): array
    {
        $from = self::DAY_FROM;
        $to = self::DAY_TO;

        foreach ($timed as $event) {
            $from = min($from, (int) $event->start_at->timezone($tz)->format('G'));
            $to = max($to, (int) $event->end_at->timezone($tz)->format('G') + 1);
        }

        return [max(0, $from), min(24, max($to, $from + 1))];
    }

    /** @return Collection<int, Event> */
    protected function events(Household $household, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Event::query()
            ->notCancelled()
            ->overlapping($from, $to)
            ->whereHas('calendar', fn ($q) => $q
                ->where('is_visible', true)
                ->whereHas('account', fn ($a) => $a->where('household_id', $household->id)))
            ->with(['calendar.member', 'members'])
            ->orderBy('all_day', 'desc')
            ->orderBy('start_at')
            ->get();
    }

    /**
     * Everything touching a day, not only what starts on it.
     *
     * @param  Collection<int, Event>  $events
     * @return Collection<int, Event>
     */
    protected function onDay(Collection $events, CarbonImmutable $day): Collection
    {
        $end = $day->endOfDay();

        return $events->filter(fn (Event $e) => $e->start_at < $end && $e->end_at > $day)->values();
    }

    /**
     * The colours a day should show, in member order and without repeats.
     *
     * @param  Collection<int, Event>  $events
     * @return list<string>
     */
    protected function coloursFor(Collection $events): array
    {
        $colours = [];

        foreach ($events as $event) {
            if ($event->members->isEmpty()) {
                $colours[] = '#94a3b8';

                continue;
            }

            foreach ($event->members as $member) {
                $colours[] = $member->colour;
            }
        }

        return array_values(array_unique($colours));
    }

    /** @return Collection<string, Collection<int, BinCollection>> */
    protected function bins(Household $household, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return BinCollection::query()
            ->where('household_id', $household->id)
            ->whereBetween('on', [$from->toDateString(), $to->toDateString()])
            ->orderBy('kind')
            ->get()
            ->groupBy(fn (BinCollection $bin) => $bin->on->toDateString());
    }
}
