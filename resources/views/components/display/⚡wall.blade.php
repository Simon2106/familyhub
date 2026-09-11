<?php

use App\Models\CaptureItem;
use App\Models\ChecklistItem;
use App\Models\Event;
use App\Services\Calendar\EventWindow;
use App\Models\Household;
use App\Models\Meal;
use App\Services\PhotoLibrary;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The wall-mounted display.
 *
 * Three weeks of events are rendered server-side in one pass and then shown or
 * hidden by Alpine, so switching between the week view, a single day, or a tab
 * costs no round trip. Livewire re-renders on a slow poll to pick up edits made
 * from phones.
 */
new #[Layout('layouts::display')] class extends Component
{
    /**
     * Days held from the start of the current week. Three weeks so that even on
     * a Sunday there is a fortnight of "coming up" ahead.
     */
    public const HORIZON = 21;

    /** The bucket for events that belong to the household rather than a person. */
    public const HOUSEHOLD = 'household';

    public function household(): Household
    {
        return Household::current();
    }

    #[Computed]
    public function members(): Collection
    {
        return $this->household()->members()->get();
    }

    /** Monday of the current week, in household time. */
    #[Computed]
    public function weekStart(): CarbonImmutable
    {
        return $this->household()->todayLocal()->startOfWeek(CarbonInterface::MONDAY);
    }

    /**
     * Every day from the start of this week, each carrying its events bucketed
     * by the members they were attributed to.
     *
     * @return list<array{
     *     date: string, carbon: CarbonImmutable, tz: string, is_today: bool,
     *     in_week: bool, events: Collection, events_by_member: Collection, count: int
     * }>
     */
    #[Computed]
    public function days(): array
    {
        $tz = $this->household()->displayTimezone();

        // Day boundaries are the family's midnights, not the server's. An event
        // at 00:30 London is 23:30 UTC the previous day; bucketing in UTC would
        // file it under the wrong day on the wall.
        $from = $this->weekStart;
        $to = $from->addDays(self::HORIZON);
        $today = $this->household()->todayLocal();

        // Occurrences rather than rows, so a weekly training is on the wall
        // every week instead of once. Eager loaded by EventWindow, so the
        // bucketing below never touches the database again.
        $events = app(EventWindow::class)
            ->between($this->household(), $from, $to)
            ->sortBy([['all_day', 'desc'], ['start_at', 'asc']])
            ->values();

        $days = [];

        for ($i = 0; $i < self::HORIZON; $i++) {
            $day = $from->addDays($i);
            $dayEnd = $day->endOfDay();

            // An event belongs to every day it touches, not just its start day,
            // so overnight and multi-day events appear where people expect them.
            $onThisDay = $events->filter(
                fn (Event $e) => $e->start_at < $dayEnd && $e->end_at > $day
            )->values();

            $days[] = [
                'date' => $day->toDateString(),
                'carbon' => $day,
                'tz' => $tz,
                'is_today' => $day->isSameDay($today),
                'in_week' => $i < 7,
                'events' => $onThisDay,
                'count' => $onThisDay->count(),
                'events_by_member' => $this->bucketByMember($onThisDay),
            ];
        }

        return $days;
    }

    /**
     * One bucket per member, plus a household bucket.
     *
     * An event attributed to several people is listed under each of them, which
     * is the point: "SW + JW dentist" should show in both columns.
     *
     * @param  Collection<int, Event>  $events
     * @return Collection<int|string, Collection<int, Event>>
     */
    protected function bucketByMember(Collection $events): Collection
    {
        $buckets = collect();

        foreach ($events as $event) {
            $memberIds = $event->members->pluck('id');

            if ($memberIds->isEmpty()) {
                $buckets->put(self::HOUSEHOLD, ($buckets->get(self::HOUSEHOLD) ?? collect())->push($event));

                continue;
            }

            foreach ($memberIds as $memberId) {
                $buckets->put($memberId, ($buckets->get($memberId) ?? collect())->push($event));
            }
        }

        return $buckets;
    }

    /** The seven days of the current week, for the strip. */
    #[Computed]
    public function week(): array
    {
        return array_slice($this->days, 0, 7);
    }

    /** Days of the current week that actually have something on. */
    #[Computed]
    public function weekWithEvents(): array
    {
        return array_values(array_filter($this->week, fn (array $day) => $day['count'] > 0));
    }

    #[Computed]
    public function weekIsEmpty(): bool
    {
        return $this->weekWithEvents === [];
    }

    /** Does anything in the whole horizon belong to the household? */
    #[Computed]
    public function hasHouseholdEvents(): bool
    {
        return collect($this->days)->contains(
            fn (array $day) => $day['events_by_member']->has(self::HOUSEHOLD)
        );
    }

    /**
     * Does anything *this week* belong to the household?
     *
     * Decides whether the week grid gets a Household column at all — an empty
     * one would cost a fifth of the width for nothing.
     */
    #[Computed]
    public function weekHasHouseholdEvents(): bool
    {
        return collect($this->week)->contains(
            fn (array $day) => ($day['events_by_member'][self::HOUSEHOLD] ?? collect())->isNotEmpty()
        );
    }

    /** Today's date string, which is what the display opens on. */
    #[Computed]
    public function todayDate(): string
    {
        return $this->today->toDateString();
    }

    /**
     * The weather, or nothing at all.
     *
     * Never throws: no weather is a missing tile, not a broken wall.
     */
    #[Computed]
    public function weather(): ?\App\Services\Weather\Forecast
    {
        return app(\App\Services\Weather\OpenMeteo::class)->current();
    }

    /** Midnight in household time, which is what every "due in N days" counts from. */
    #[Computed]
    public function today(): CarbonImmutable
    {
        return $this->household()->todayLocal();
    }

    /**
     * Days one of the schools is shut, across the shown fortnight.
     *
     * Term time is the default state of a household with children in it, so
     * the wall says nothing during it. What it shows is the exceptions — the
     * holidays and the INSET days somebody has to have arranged something for.
     *
     * @return Collection<string, Collection<int, \App\Services\Schools\SchoolClosure>>
     */
    /** Up to three things the household is counting the days to. */
    #[Computed]
    public function countdowns(): \Illuminate\Support\Collection
    {
        return app(\App\Services\Countdowns\CountdownBoard::class)->upcoming($this->household(), 3);
    }

    /** Months either side of this one, for paging the month grid. */
    public int $monthOffset = 0;

    /**
     * The day the timeline is showing.
     *
     * A Livewire property rather than Alpine state because the placement is
     * worked out on the server; set once when the timeline is opened, so
     * picking days in the column view stays instant.
     */
    public ?string $timelineDate = null;

    #[Computed]
    public function month(): array
    {
        return app(\App\Services\Calendar\CalendarViews::class)->month(
            $this->household(),
            $this->household()->todayLocal()->startOfMonth()->addMonths($this->monthOffset),
        );
    }

    #[Computed]
    public function timeline(): array
    {
        $date = $this->timelineDate
            ? CarbonImmutable::parse($this->timelineDate, $this->household()->displayTimezone())
            : $this->household()->todayLocal();

        return app(\App\Services\Calendar\CalendarViews::class)->day($this->household(), $date);
    }

    public function shiftMonth(int $by): void
    {
        $this->monthOffset = max(-24, min(24, $this->monthOffset + $by));

        unset($this->month);
    }

    public function showMonth(): void
    {
        $this->monthOffset = 0;

        unset($this->month);
    }

    /**
     * Whether the wall has anything to listen with.
     *
     * Without a key the microphone can only ever apologise, so the button does
     * not appear at all — a kiosk with no keyboard is the worst possible place
     * to discover a missing setting.
     */
    #[Computed]
    public function canListen(): bool
    {
        return app(\App\Services\Speech\Contracts\SpeechToText::class)->isConfigured();
    }

    #[Computed]
    public function schoolClosures(): Collection
    {
        $from = $this->weekStart;
        $to = $from->addDays(self::HORIZON);

        $closures = app(\App\Services\Schools\SchoolCalendar::class)
            ->closures($this->household(), $from, $to);

        $byDate = collect();

        for ($date = $from; ! $date->greaterThan($to); $date = $date->addDay()) {
            $onThisDay = $closures->filter(fn ($closure) => $closure->covers($date))->values();

            if ($onThisDay->isNotEmpty()) {
                $byDate->put($date->toDateString(), $onThisDay);
            }
        }

        return $byDate;
    }

    /**
     * The last day of term and the first day back, when they are close.
     *
     * @return Collection<int, array{code: string, label: string, on: CarbonImmutable}>
     */
    #[Computed]
    public function schoolTurningPoints(): Collection
    {
        return app(\App\Services\Schools\SchoolCalendar::class)
            ->turningPoints($this->household(), $this->today);
    }

    /**
     * The next bin collection worth mentioning.
     *
     * Only within the next few days: a wall that permanently says "recycling,
     * a week on Tuesday" is a wall nobody reads. Two bins on the same day are
     * one line, because that is how it feels from the kitchen.
     *
     * @return Collection<int, \App\Models\BinCollection>
     */
    #[Computed]
    public function nextBins(): Collection
    {
        $today = $this->today->toDateString();

        $next = \App\Models\BinCollection::query()
            ->where('household_id', $this->household()->id)
            ->upcoming($today)
            ->first();

        if (! $next || $next->on->toDateString() > $this->today->addDays(3)->toDateString()) {
            return collect();
        }

        return \App\Models\BinCollection::query()
            ->where('household_id', $this->household()->id)
            ->where('on', $next->on->toDateString())
            ->orderBy('kind')
            ->get();
    }

    /**
     * The week's dinners, keyed by date, for the home view.
     *
     * Dinner only: it is the meal a household actually plans, and the one
     * worth a line on a screen read from across the kitchen.
     *
     * @return Collection<string, Meal>
     */
    #[Computed]
    public function dinners(): Collection
    {
        return Meal::query()
            ->where('household_id', $this->household()->id)
            ->where('slot', 'dinner')
            ->between($this->weekStart->toDateString(), $this->weekStart->addDays(self::HORIZON)->toDateString())
            ->get()
            ->keyBy(fn (Meal $meal) => $meal->on->toDateString());
    }

    #[On('meals-changed')]
    public function refreshDinners(): void
    {
        unset($this->dinners);
    }

    /**
     * Chores due across the shown fortnight, by date then member.
     *
     * Reading this writes nothing: a chore nobody has touched has no row, and
     * "not done yet" is exactly the state a child most needs to see.
     *
     * @return Collection<string, Collection<int|string, Collection<int, \App\Services\Chores\ChoreSlot>>>
     */
    #[Computed]
    public function choresByDate(): Collection
    {
        return app(\App\Services\Chores\ChoreBoard::class)->forRange(
            $this->household(),
            $this->weekStart,
            $this->weekStart->addDays(self::HORIZON),
        );
    }

    #[On('chores-changed')]
    public function refreshChores(): void
    {
        unset($this->choresByDate);
    }

    /**
     * Routines running right now, by member.
     *
     * Only the running one: a list of everything a child does all day is a
     * poster, not something to act on during the morning rush.
     *
     * @return Collection<int, \App\Services\Routines\RoutineProgress>
     */
    #[Computed]
    public function runningRoutines(): Collection
    {
        return app(\App\Services\Routines\RoutineBoard::class)
            ->activeAcross($this->household(), $this->today, $this->household()->nowLocal())
            ->keyBy(fn ($progress) => $progress->routine->member_id);
    }

    #[On('routines-changed')]
    public function refreshRoutines(): void
    {
        unset($this->runningRoutines);
    }

    /** Everything from tomorrow onwards, as a flat list for the "coming up" rail. */
    #[Computed]
    public function upcoming(): Collection
    {
        $today = $this->household()->todayLocal();

        return collect($this->days)
            ->filter(fn (array $day) => $day['carbon']->greaterThan($today))
            ->flatMap(fn (array $day) => $day['events']->map(
                fn (Event $e) => ['event' => $e, 'day' => $day['carbon']]
            ))
            ->take(12)
            ->values();
    }

    /**
     * Open, dated to-dos bucketed by date and then by member, for the day
     * columns. Undated ones live only in the To do panel.
     *
     * A to-do only appears once it has surfaced, so a deadline six weeks out
     * does not sit in someone's column all term.
     *
     * @return Collection<string, Collection<int|string, Collection<int, ChecklistItem>>>
     */
    #[Computed]
    public function todosByDate(): Collection
    {
        $from = $this->weekStart;
        $to = $from->addDays(self::HORIZON);

        return ChecklistItem::query()
            ->whereHas('checklist', fn ($q) => $q
                ->where('household_id', $this->household()->id)
                ->where('is_home_list', true))
            ->open()
            ->surfaced()
            ->whereNotNull('due_on')
            ->whereBetween('due_on', [$from->toDateString(), $to->toDateString()])
            ->with(['member', 'event'])
            ->inDueOrder()
            ->get()
            ->groupBy(fn (ChecklistItem $item) => $item->due_on->toDateString())
            ->map(fn (Collection $items) => $items->groupBy(
                fn (ChecklistItem $item) => $item->member_id ?? self::HOUSEHOLD
            ));
    }

    #[On('todos-changed')]
    public function refreshTodos(): void
    {
        unset($this->todosByDate);
    }

    /** How many captured items are waiting for someone to decide. */
    #[Computed]
    public function reviewCount(): int
    {
        return CaptureItem::query()
            ->whereHas('capture', fn ($q) => $q->where('household_id', $this->household()->id))
            ->pending()
            ->count();
    }

    #[On('captures-changed')]
    public function refreshReviewCount(): void
    {
        unset($this->reviewCount);
    }

    #[Computed]
    public function checklists(): Collection
    {
        return $this->household()->checklists()->with('items')->get();
    }

    /** @return list<string> */
    /**
     * The wall's own settings, re-read on every poll.
     *
     * These live in the household rather than in config so /admin can change
     * them, and they are read here rather than only in the layout so a change
     * reaches the wall within a minute instead of at the next reload.
     *
     * @return array{darkStart: string, darkEnd: string, idleMs: int, style: string}
     */
    #[Computed]
    public function wallSettings(): array
    {
        $dark = $this->household()->darkMode();

        return [
            'darkStart' => $dark['start'],
            'darkEnd' => $dark['end'],
            'idleMs' => $this->household()->screensaverMinutes() * 60 * 1000,
            'style' => $this->household()->screensaverStyle(),
        ];
    }

    /**
     * How much of the screen a drifting block may wander across.
     *
     * Small on purpose. Each block keeps to its own corner, and the reason
     * they cannot collide is that their rooms do not overlap — not that
     * anything checks.
     */
    public const SAVER_DRIFT_SHARE = 0.06;

    /**
     * How close to the glass a drifting block may come.
     *
     * The same as the padding it is laid out with: a block allowed to reach
     * the edge of the screen is one whose letters get half-eaten by the bezel.
     */
    public const SAVER_EDGE_MARGIN = 40;

    /** As many as fit on a wall and still read from the other side of a room. */
    public const SAVER_LINES = 6;

    /** Fewer for tomorrow: it is a look-ahead, not the day's agenda. */
    public const SAVER_TOMORROW_LINES = 4;

    /**
     * What is left today, for the screensaver that shows the day.
     *
     * Everyone's, not one person's, and everything left rather than the next
     * thing — a family walking past at six wants to know what is still to
     * come, and "one event" makes a five o'clock pick-up invisible the moment
     * a four o'clock one exists.
     *
     * Falls forward to tomorrow once the day is done, because an empty panel
     * at nine in the evening tells nobody anything.
     *
     * @return array{label: ?string, events: list<array<string, mixed>>, more: int}
     */
    #[Computed]
    public function saverAgenda(): array
    {
        $now = $this->household()->nowLocal();

        $today = $this->saverLines($now, $now->endOfDay())
            // Only what is still to come. An all-day thing counts all day.
            ->filter(fn (array $line) => $line['all_day'] || $line['ends_at']->greaterThanOrEqualTo($now))
            ->values();

        if ($today->isNotEmpty()) {
            return $this->saverPanel(null, $today, self::SAVER_LINES);
        }

        $tomorrow = $now->addDay();

        return $this->saverPanel(
            'Tomorrow',
            $this->saverLines($tomorrow->startOfDay(), $tomorrow->endOfDay()),
            self::SAVER_TOMORROW_LINES,
        );
    }

    /**
     * One line per event: whose it is, when, and what.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    protected function saverLines(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $tz = $this->household()->displayTimezone();

        return app(EventWindow::class)
            ->between($this->household(), $from, $to)
            ->map(function (Event $event) use ($tz) {
                $members = $event->members;

                return [
                    'key' => $event->occurrence_id ?? $event->id,
                    'title' => $event->title,
                    'all_day' => (bool) $event->all_day,
                    'starts_at' => $event->start_at->timezone($tz),
                    'ends_at' => $event->end_at->timezone($tz),
                    'when' => $event->all_day ? 'All day' : $event->start_at->timezone($tz)->format('H:i'),
                    // Two at most: a household outing belongs to everybody and
                    // four sets of initials is a line nobody reads.
                    'who' => $members->take(2)->map(fn ($m) => [
                        'initials' => $m->initials(),
                        'colour' => $m->colour,
                    ])->values()->toBase()->all(),
                    'extra_people' => max(0, $members->count() - 2),
                ];
            })
            // All-day things first, then by the clock, which is how the rest
            // of the wall orders a day.
            ->sortBy([['all_day', 'desc'], ['starts_at', 'asc']])
            ->values();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $lines
     * @return array{label: ?string, events: list<array<string, mixed>>, more: int}
     */
    protected function saverPanel(?string $label, Collection $lines, int $limit): array
    {
        return [
            'label' => $label,
            'events' => $lines->take($limit)->values()->toBase()->all(),
            'more' => max(0, $lines->count() - $limit),
        ];
    }

    #[Computed]
    public function photos(): array
    {
        // The caption travels with the URL: the screensaver cycles in the
        // browser, so anything it wants to draw has to be in hand before it
        // starts.
        return app(PhotoLibrary::class)->photos()
            ->map(fn (\App\Models\Photo $photo) => [
                'url' => $photo->url(),
                'caption' => $photo->caption,
            ])
            ->all();
    }
}; ?>

@php
    $members = $this->members;
    $days = $this->days;
    $week = $this->week;
    $tz = $this->household()->displayTimezone();
    $wall = $this->wallSettings;
@endphp

<div
    class="app-shell flex flex-col text-slate-900 dark:text-slate-100"
    wire:poll.60s
    x-data="{
        /* --- local state: never round-trips to the server --- */
        view: 'day',           /* the display opens on today */
        today: @js($this->todayDate),
        selected: @js($this->todayDate),
        tab: 'home',
        searching: false,
        now: new Date(),
        idle: false,
        idleSince: 0,
        photoIndex: 0,

        /* Two layers that cross-fade into each other, rather than one whose
           src is swapped — a swap is a blink, and this is a room people are
           sitting in. */
        slotA: null,
        slotB: null,
        captionA: null,
        captionB: null,
        showA: true,
        photos: @js($this->photos),
        idleMs: @js($wall['idleMs']),
        saverStyle: @js($wall['style']),
        darkStart: @js($wall['darkStart']),
        darkEnd: @js($wall['darkEnd']),
        drift: { x: 0, y: 0 },

        /* One path each, so the three never travel as a block. */
        driftClock: { x: 0, y: 0 },
        driftEvents: { x: 0, y: 0 },
        driftWeather: { x: 0, y: 0 },
        tz: @js($tz),
        weekDates: @js(array_column($week, 'date')),

        init() {
            /* Remembered per device, not per household: the wall in the
               kitchen and a phone previewing it want different things, and
               neither should have to be set again every morning. */
            try {
                const kept = localStorage.getItem('familyhub.view');

                if (['day', 'week', 'month', 'timeline'].includes(kept)) {
                    this.view = kept;
                    if (kept === 'timeline') this.$wire.set('timelineDate', this.selected, false);
                }
            } catch {
                /* Private browsing, or storage switched off. The default view
                   is a perfectly good answer. */
            }

            this.$watch('view', (to) => {
                try {
                    localStorage.setItem('familyhub.view', to);
                } catch {
                    /* As above. */
                }
            });

            setInterval(() => (this.now = new Date()), 1000);
            this.armIdleTimer();
            this.applyDarkSchedule();

            /* The settings come back down on every poll, so a change made in
               /admin reaches the wall within the minute rather than at the
               next reload. */
            this.$watch('darkStart', () => this.applyDarkSchedule());
            this.$watch('darkEnd', () => this.applyDarkSchedule());
            this.$watch('idleMs', () => this.armIdleTimer());

            /* Five steps a second: at the speed this moves that is under a
               pixel and a half a step, and the panel may only run at 30Hz. */
            setInterval(() => this.stepDrift(), 200);

            this.slotA = this.photos[0]?.url ?? null;
            this.captionA = this.photos[0]?.caption ?? null;

            if (this.photos.length > 1) {
                setInterval(() => {
                    if (this.idle) this.nextPhoto();
                }, @js(config('familyhub.screensaver.interval_seconds') * 1000));
            }

            /* New photographs, without waiting for a reload. The album is
               polled hourly on the server; this is how the wall finds out,
               and it only asks while the screensaver is actually up. */
            setInterval(() => this.refreshPhotos(), @js(config('familyhub.screensaver.refresh_minutes') * 60 * 1000));
        },

        /* Tapping the day already showing goes back to today — the tap that
           drilled in backs out again. On today itself there is nowhere further
           back to go, so it simply stays put. */
        pickDay(date) {
            if (this.view === 'day' && this.selected === date) {
                this.showToday();
            } else {
                this.view = 'day';
                this.selected = date;
            }
        },

        showMonthView() {
            this.view = 'month';
            this.$wire.showMonth();
        },

        /* The timeline needs the server to place the events, so opening it
           sends the day across once rather than on every tap. */
        showTimeline(date) {
            this.selected = date || this.selected;
            this.view = 'timeline';
            this.$wire.set('timelineDate', this.selected);
        },

        showToday() {
            this.view = 'day';
            this.selected = this.today;
        },

        showWeek() {
            this.view = 'week';
        },

        get onToday() {
            return this.view === 'day' && this.selected === this.today;
        },

        isPicked(date) {
            return this.view === 'day' && this.selected === date;
        },

        /* Swiping the agenda moves a day at a time within the week. */
        shiftDay(delta) {
            if (this.view !== 'day') return;
            const at = this.weekDates.indexOf(this.selected);
            if (at === -1) return;
            const next = at + delta;
            if (next < 0 || next >= this.weekDates.length) return;
            this.selected = this.weekDates[next];
        },

        /* Handed to the plain, framework-free applier in app.js, which is what
           runs the schedule whether or not Livewire ever booted. */
        applyDarkSchedule() {
            const root = document.documentElement;

            root.dataset.darkStart = this.darkStart;
            root.dataset.darkEnd = this.darkEnd;

            window.familyhubDarkMode?.();
        },

        /* The next photograph, faded in behind the one showing. */
        nextPhoto() {
            if (this.photos.length < 2) return;

            this.photoIndex = (this.photoIndex + 1) % this.photos.length;

            const next = this.photos[this.photoIndex];

            /* Loaded into whichever layer is currently hidden, then the two
               are swapped — so the fade is between two images that are both
               already decoded. */
            if (this.showA) {
                this.slotB = next.url;
                this.captionB = next.caption;
            } else {
                this.slotA = next.url;
                this.captionA = next.caption;
            }

            this.showA = ! this.showA;
        },

        /* Whatever the album has now. Quietly: a failure here means the wall
           keeps showing the photographs it already has, which is right. */
        async refreshPhotos() {
            if (! this.idle) return;

            try {
                const response = await fetch(@js(route('display.photos')), {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                });

                if (! response.ok) return;

                const fresh = await response.json();

                if (! Array.isArray(fresh.photos) || fresh.photos.length === 0) return;

                /* Only when something has actually changed, so a poll does
                   not restart the slideshow every hour. */
                if (fresh.photos.length === this.photos.length
                    && fresh.photos[0]?.url === this.photos[0]?.url) {
                    return;
                }

                this.photos = fresh.photos;
                this.photoIndex = 0;
            } catch (e) {
                /* Offline, or the server is mid-deploy. Try again next hour. */
            }
        },

        /*
         * The wander, one path per block.
         *
         * Over a photograph the burn-in argument is already answered by the
         * picture changing — but a panel showing a still image of anything is
         * a panel with that image faintly in it by Christmas, and the text is
         * the brightest thing on the screen. So everything moves.
         *
         * Each block is given a corner of the screen to wander inside and
         * never leaves it, which is what keeps them from colliding: they
         * cannot overlap if their rooms do not. The periods differ so the
         * three are never in step, which would read as the whole screen
         * sliding rather than three things drifting.
         */
        stepDrift() {
            if (!this.idle) return;

            const screen = this.$refs.screensaver;

            if (!screen) return;

            const frame = screen.getBoundingClientRect();
            const elapsed = Date.now() - this.idleSince;

            if (this.saverStyle === 'photos') {
                this.drift = { x: 0, y: 0 };

                this.driftClock = this.driftWithin(
                    frame, this.$refs.saverClock, elapsed, {}, this.driftClock,
                );
                this.driftEvents = this.driftWithin(
                    frame, this.$refs.saverEvents, elapsed,
                    { periodX: 690_000, periodY: 870_000, phase: Math.PI },
                    this.driftEvents,
                );
                this.driftWeather = this.driftWithin(
                    frame, this.$refs.saverWeather, elapsed,
                    { periodX: 810_000, periodY: 960_000, phase: Math.PI / 4 },
                    this.driftWeather,
                );

                return;
            }

            const clock = this.$refs.driftingClock;

            if (!clock) return;

            /* The same margin the blocks over a photograph keep: a clock this
               size is only a few hundred pixels from the edge to begin with,
               and one that reaches the glass has its descenders in the bezel. */
            const inset = @js(self::SAVER_EDGE_MARGIN);
            const inner = {
                width: Math.max(0, frame.width - inset * 2),
                height: Math.max(0, frame.height - inset * 2),
            };

            const room = window.familyhubDrift.roomFor(inner, clock.getBoundingClientRect());
            const at = window.familyhubDrift.driftAt(elapsed, room);

            this.drift = { x: at.x + inset, y: at.y + inset };
        },

        /*
         * How far one block may wander from where it is pinned.
         *
         * A fraction of whatever space its corner has spare, rather than the
         * whole of it: a block that could cross the screen would meet the
         * others in the middle, and the point of this is that it never has to
         * be asked whether they collide.
         */
        driftWithin(frame, element, elapsed, options, current) {
            if (!element) return { x: 0, y: 0 };

            const box = element.getBoundingClientRect();

            /* Where the block sits with no transform on it. The rect we just
               measured has the current offset baked in, so it has to come
               back out or each step would compound the last. */
            const pinned = {
                left: box.left - current.x,
                right: box.right - current.x,
                top: box.top - current.y,
                bottom: box.bottom - current.y,
            };

            const share = @js(self::SAVER_DRIFT_SHARE);

            const wanted = window.familyhubDrift.driftAt(
                elapsed,
                { x: frame.width * share, y: frame.height * share },
                options,
            );

            /* Centred on where it is pinned, so it wanders both ways out of
               its corner rather than only inwards — then clamped to the frame,
               because a block pinned against an edge has nowhere to go that
               way and must not be pushed off it. */
            const offset = {
                x: wanted.x - (frame.width * share) / 2,
                y: wanted.y - (frame.height * share) / 2,
            };

            const clamp = (value, low, high) => Math.max(low, Math.min(high, value));

            /* Against the padded area rather than the glass. Clamping to the
               frame lets a block drift until it is flush with the edge of the
               screen, which on a panel in a bezel means letters half-eaten. */
            const inset = @js(self::SAVER_EDGE_MARGIN);

            return {
                x: clamp(offset.x, frame.left + inset - pinned.left, frame.right - inset - pinned.right),
                y: clamp(offset.y, frame.top + inset - pinned.top, frame.bottom - inset - pinned.bottom),
            };
        },

        armIdleTimer() {
            if (!this.idleMs) return;
            clearTimeout(this.idleTimer);
            this.idleTimer = setTimeout(() => {
                this.idleSince = Date.now();
                this.idle = true;
            }, this.idleMs);
        },

        wake() {
            this.idle = false;
            this.armIdleTimer();
        },

        get clock() {
            return this.now.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', timeZone: this.tz });
        },
    }"
    x-on:pointerdown.window="wake()"
    x-on:keydown.window="wake()"
>
    {{-- ============================= HEADER ============================= --}}
    <header class="flex shrink-0 items-baseline justify-between gap-6 px-6 pt-4 pb-3 sm:px-8">
        <div class="flex items-baseline gap-4">
            {{-- The same face as the screensaver, and tnum for the same
                 reason: this one is on screen all day. --}}
            <span class="display-digits text-4xl font-bold" x-text="clock">&nbsp;</span>

            <div x-show="view === 'week'">
                <p class="text-xl font-semibold">This week</p>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ $week[0]['carbon']->format('j M') }} – {{ $week[6]['carbon']->format('j M') }}
                </p>
            </div>

            <div x-show="view === 'day'" x-cloak>
                <p class="text-xl font-semibold"
                   x-text="selected && new Date(selected + 'T00:00:00').toLocaleDateString('en-GB', { weekday: 'long' })"></p>
                <p class="text-sm text-slate-500 dark:text-slate-400"
                   x-text="selected && new Date(selected + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'long' })"></p>
            </div>

            {{-- The last day of term and the first day back. Worth a line for
                 three days either side and silence the rest of the year. --}}
            @foreach ($this->schoolTurningPoints as $point)
                <div class="hidden min-w-0 items-baseline gap-2 rounded-xl px-3 py-1 sm:flex" wire:key="turn-{{ $point['code'] }}-{{ $point['on']->toDateString() }}">
                    <span class="rounded-lg px-1.5 text-sm font-bold text-white" style="background-color: #0d9488;">{{ $point['code'] }}</span>
                    <span class="truncate text-lg font-semibold">{{ $point['label'] }}</span>
                    @if ($point['finishes'])
                        {{-- The half day nobody remembers until they are still
                             at work at one o'clock. --}}
                        <span class="shrink-0 text-lg font-bold text-amber-600 dark:text-amber-400">
                            finishes {{ $point['finishes'] }}
                        </span>
                    @endif
                    <span class="text-sm text-slate-500 dark:text-slate-400">
                        {{ $point['on']->isSameDay($this->today) ? 'today' : ($point['on']->isSameDay($this->today->addDay()) ? 'tomorrow' : $point['on']->format('D')) }}
                    </span>
                </div>
            @endforeach

            {{-- What the household is counting the days to. Beside the clock
                 because it is the other thing people look at on their way
                 past, and because it is the only part of the header that is
                 good news. --}}
            <x-countdown-strip :entries="$this->countdowns" class="hidden xl:flex" />

            {{-- Bins, when they are close enough to matter. Beside the clock
                 because it is a thing you check on your way past. --}}
            @if ($this->nextBins->isNotEmpty())
                @php
                    $binDay = $this->nextBins->first()->on;
                    $isToday = $binDay->isSameDay($this->today);
                    $isTomorrow = $binDay->isSameDay($this->today->addDay());
                    // Loud on the two days it can still be acted on, quiet the
                    // rest of the fortnight. A badge that always shouts is a
                    // badge nobody reads by Thursday.
                    $urgent = $isToday || $isTomorrow;
                    $when = $isToday ? 'today' : ($isTomorrow ? 'tonight' : $binDay->format('D'));
                @endphp

                <div class="hidden shrink-0 items-center gap-2.5 rounded-full px-4 py-1.5 sm:flex
                            {{ $urgent ? 'text-white shadow-sm' : 'bg-slate-100 dark:bg-slate-800' }}"
                     @if ($urgent) style="background-color: {{ $this->nextBins->first()->colour() }};" @endif>

                    {{-- The colours first: from across a kitchen the dots are
                         read before any of the words are.

                         Backed by a dark chip when the pill is coloured, or a
                         yellow food bin on a yellow pill disappears into it. --}}
                    <span class="flex shrink-0 items-center gap-1 rounded-full {{ $urgent ? 'bg-black/25 px-1.5 py-1' : '' }}"
                          aria-hidden="true">
                        @foreach ($this->nextBins as $bin)
                            <span class="size-3.5 rounded-full {{ $urgent ? '' : 'ring-2 ring-white dark:ring-slate-800' }}"
                                  style="background-color: {{ $bin->colour() }};"></span>
                        @endforeach
                    </span>

                    <span class="truncate font-bold {{ $urgent ? 'text-lg' : 'text-base text-slate-700 dark:text-slate-200' }}">
                        {{ $this->nextBins->map(fn ($bin) => $bin->label())->join(' + ') }}
                    </span>

                    <span class="shrink-0 font-bold {{ $urgent ? 'text-lg text-white/85' : 'text-base text-slate-500 dark:text-slate-400' }}">
                        {{ $when }}
                    </span>
                </div>
            @endif

            {{-- The question actually asked in a kitchen, answered without
                 anyone having to change tab. --}}
            @php $tonight = $this->dinners[$this->todayDate] ?? null; @endphp

            @if ($tonight)
                <button type="button" x-on:click="tab = 'meals'"
                        class="hidden min-w-0 items-baseline gap-2 rounded-xl px-3 py-1 text-left sm:flex">
                    <span class="text-sm font-semibold tracking-wide text-slate-400 uppercase">Tonight</span>
                    <span class="truncate text-lg font-semibold">{{ $tonight->title }}</span>
                </button>
            @endif
        </div>

        <div class="flex items-center gap-5">
            <x-weather-tile :forecast="$this->weather" class="hidden sm:flex" />

            {{-- Ask out loud. Only where there is something to listen with:
                 without a key this is a button that can only disappoint.

                 The dialog lives in here with the button rather than beside
                 the search one, so the two share a single Alpine scope. It is
                 fixed-positioned, so where it sits in the document does not
                 decide where it lands on the screen. --}}
            @if ($this->canListen)
                <div x-data="wallMic({
                        listen: @js(route('display.listen')),
                        speech: @js(Str::beforeLast(route('display.speech', ['id' => 'x']), '/x')),
                     })">
                    <button type="button" x-on:click="press()"
                            class="grid touch-target shrink-0 place-items-center rounded-2xl transition-colors"
                            :class="{
                                'text-slate-400': state === 'idle',
                                'text-rose-500': state === 'listening',
                                'text-blue-500': state === 'thinking',
                                'text-emerald-500': state === 'speaking',
                                'text-amber-500': state === 'failed',
                            }"
                            :aria-label="state === 'listening' ? 'Stop listening' : 'Ask a question'">
                        <span class="relative grid place-items-center">
                            {{-- A ring while it listens, a slow pulse while it
                                 thinks. Subtle on purpose: this sits in a
                                 header somebody walks past all day. --}}
                            <span x-show="state === 'listening'" x-cloak
                                  class="absolute inline-flex size-11 animate-ping rounded-full bg-rose-500/30"></span>
                            <span x-show="state === 'thinking' || state === 'speaking'" x-cloak
                                  class="absolute inline-flex size-11 animate-pulse rounded-full bg-current opacity-10"></span>

                            <svg class="relative size-7" fill="none" stroke="currentColor" stroke-width="2"
                                 stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                                <rect x="9" y="3" width="6" height="11" rx="3" />
                                <path d="M5 11a7 7 0 0 0 14 0M12 18v3" />
                            </svg>
                        </span>
                    </button>

                    {{-- What was asked and what came back. Dismissed by a tap
                         anywhere or by thirty seconds of nobody doing
                         anything: nobody walks back to a wall to close a
                         dialog. --}}
                    <div x-show="open" x-cloak x-on:keydown.escape.window="dismiss()">
                        <x-modal close-on="tapped()" label="Asking" width="max-w-2xl">
                            <div class="p-6" x-on:click="tapped()">
                                <p class="text-sm font-semibold tracking-wide text-slate-400 uppercase"
                                   x-text="{
                                       listening: 'Listening…',
                                       thinking: 'Thinking…',
                                       speaking: 'Answering',
                                       failed: 'Sorry',
                                       idle: 'Answered',
                                   }[state]"></p>

                                {{-- The question first, so it is obvious what
                                     was heard. Half of what goes wrong here is
                                     a misheard word, and showing it turns a
                                     baffling answer into an obvious one. --}}
                                <p x-show="transcript" x-cloak x-text="transcript"
                                   class="mt-3 text-2xl font-semibold"></p>

                                <p x-show="state === 'listening' && ! transcript" x-cloak
                                   class="mt-3 text-2xl font-semibold text-slate-400">Go ahead…</p>

                                {{-- The dialog covers the mic button the
                                     moment it opens, so "tap again to stop"
                                     has to mean anywhere. --}}
                                <p x-show="state === 'listening'" x-cloak
                                   class="mt-2 text-sm text-slate-400">Tap anywhere when you have finished.</p>

                                <p x-show="answer" x-cloak x-text="answer"
                                   class="mt-4 text-3xl leading-snug whitespace-pre-line"></p>

                                <p x-show="error" x-cloak x-text="error"
                                   class="mt-4 text-2xl text-amber-600 dark:text-amber-400"></p>

                                <p class="mt-6 text-sm text-slate-400" x-show="state !== 'listening'" x-cloak>
                                    Read-only — it looks things up, it never changes anything. Tap to close.
                                </p>
                            </div>
                        </x-modal>
                    </div>
                </div>
            @endif

            <button type="button" x-on:click="searching = true"
                class="grid touch-target shrink-0 place-items-center rounded-2xl text-slate-400"
                aria-label="Search">
            <svg class="size-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="11" cy="11" r="7" />
                <path d="m20 20-3.5-3.5" />
            </svg>
        </button>

        <div class="text-right">
            <p class="text-sm font-medium text-slate-500 dark:text-slate-400"
               title="Build {{ \App\Support\BuildVersion::current() }}"
               data-build-version="{{ \App\Support\BuildVersion::current() }}">{{ $this->household()->name }}</p>
            <button
                type="button"
                x-show="! onToday"
                x-cloak
                x-on:click="showToday()"
                class="touch-target -mr-2 rounded-lg px-3 text-sm font-semibold text-blue-600 dark:text-blue-400"
            >
                Back to today
            </button>
            </div>
        </div>
    </header>

    {{-- ============================== BODY ============================== --}}
    <div class="min-h-0 flex-1 px-6 pb-3 sm:px-8">

        {{-- ---------------------------- HOME ---------------------------- --}}
        <div x-show="tab === 'home'" class="flex h-full min-h-0 flex-col gap-3">
          <div class="grid min-h-0 flex-1 gap-4 lg:grid-cols-5">

            <section
                class="min-h-0 lg:col-span-3"
                x-on:touchstart="$data.swipeFrom = $event.changedTouches[0].clientX"
                x-on:touchend="
                    const dx = $event.changedTouches[0].clientX - ($data.swipeFrom ?? 0);
                    if (Math.abs(dx) > 60) shiftDay(dx < 0 ? 1 : -1);
                "
            >
                {{-- ===================== WHOLE WEEK ===================== --}}
                @php
                    $weekColumns = $members->count() + ($this->weekHasHouseholdEvents ? 1 : 0);
                @endphp

                {{-- The month. Paged on the server, because a month is a
                     different set of events rather than a different slice of
                     the ones already loaded. --}}
                <div x-show="view === 'month'" class="flex h-full min-h-0 flex-col" x-cloak>
                    <div class="flex shrink-0 items-center gap-2 pb-2">
                        <button type="button" wire:click="shiftMonth(-1)"
                                class="grid touch-target place-items-center rounded-xl px-3 text-slate-500"
                                aria-label="The month before">
                            <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="m15 6-6 6 6 6" /></svg>
                        </button>

                        <h2 class="min-w-0 flex-1 text-center text-xl font-bold">
                            {{ $this->month['anchor']->format('F Y') }}
                        </h2>

                        <button type="button" wire:click="shiftMonth(1)"
                                class="grid touch-target place-items-center rounded-xl px-3 text-slate-500"
                                aria-label="The month after">
                            <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6" /></svg>
                        </button>
                    </div>

                    <div class="min-h-0 flex-1">
                        <x-calendar.month :month="$this->month" :on-wall="true" />
                    </div>
                </div>

                {{-- One day, hour by hour. --}}
                <div x-show="view === 'timeline'" class="flex h-full min-h-0 flex-col" x-cloak>
                    <div class="flex shrink-0 items-center gap-2 pb-2">
                        <h2 class="min-w-0 flex-1 text-xl font-bold">
                            {{ $this->timeline['date']->format('l j F') }}
                        </h2>
                        {{-- Only where the columns actually have that day
                             loaded; offering it for next March would open an
                             empty screen. --}}
                        <button type="button"
                                x-show="weekDates.includes(@js($this->timeline['date']->toDateString()))"
                                x-cloak
                                x-on:click="selected = @js($this->timeline['date']->toDateString()); view = 'day'"
                                class="touch-target rounded-xl px-3 text-sm font-semibold text-blue-600 dark:text-blue-400">
                            By person
                        </button>
                    </div>

                    <div class="min-h-0 flex-1">
                        <x-calendar.day :day="$this->timeline" :on-wall="true" />
                    </div>
                </div>

                <div x-show="view === 'week'" class="h-full min-h-0" x-cloak>

                    {{-- Landscape: a member-by-day grid. Sized to fit five
                         columns without sideways scrolling on an iPad. --}}
                    <div class="pane-scroll hidden h-full min-h-0 rounded-2xl bg-white p-2 lg:block dark:bg-slate-900">
                        <div class="grid gap-x-1"
                             style="grid-template-columns: 3.5rem repeat({{ max($weekColumns, 1) }}, minmax(0, 1fr));">

                            {{-- Header row --}}
                            <div class="sticky top-0 z-10 bg-white dark:bg-slate-900"></div>
                            @foreach ($members as $member)
                                {{-- The dot stays the cue; the whole heading is
                                     the target. A child's opens their own day,
                                     which is the thing behind their name;
                                     anybody else's opens today, because an
                                     adult's column already is their view. --}}
                                <button type="button"
                                        @if ($member->is_child)
                                            wire:click="$dispatch('show-my-day', { member: {{ $member->id }} })"
                                        @else
                                            x-on:click="showToday()"
                                        @endif
                                        class="sticky top-0 z-10 flex touch-target items-center gap-1.5 bg-white px-1.5 pb-1 text-left dark:bg-slate-900"
                                        aria-label="{{ $member->is_child ? $member->name."'s day" : $member->name }}"
                                        wire:key="wk-head-{{ $member->id }}">
                                    <span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $member->colour }};"></span>
                                    <span class="truncate text-sm font-semibold">{{ $member->name }}</span>
                                </button>
                            @endforeach
                            @if ($this->weekHasHouseholdEvents)
                                <div class="sticky top-0 z-10 flex items-center gap-1.5 bg-white px-1.5 pb-1 dark:bg-slate-900">
                                    <span class="size-2.5 shrink-0 rounded-full bg-slate-400"></span>
                                    <span class="truncate text-sm font-semibold">Household</span>
                                </div>
                            @endif

                            {{-- One row per day --}}
                            @foreach ($week as $day)
                                @php
                                    $rowTint = $day['is_today']
                                        ? 'bg-blue-50 dark:bg-blue-950/40'
                                        : 'border-t border-slate-100 dark:border-slate-800';
                                @endphp

                                <button
                                    type="button"
                                    data-grid-date="{{ $day['date'] }}"
                                    x-on:click="pickDay(@js($day['date']))"
                                    class="flex touch-target flex-col items-start justify-start px-1 py-1.5 text-left {{ $rowTint }} {{ $day['is_today'] ? 'rounded-l-lg' : '' }}"
                                    wire:key="wk-day-{{ $day['date'] }}"
                                >
                                    <span class="text-xs font-semibold {{ $day['is_today'] ? 'text-blue-600 dark:text-blue-400' : 'text-slate-400' }}">
                                        {{ $day['carbon']->format('D') }}
                                    </span>
                                    <span class="text-sm leading-tight font-bold tabular-nums">{{ $day['carbon']->format('j') }}</span>
                                </button>

                                @foreach ($members as $member)
                                    @php $cellEvents = $day['events_by_member'][$member->id] ?? collect(); @endphp
                                    {{-- The whole cell, not just the date at the
                                         end of the row: a finger goes to the
                                         name it is reading, and a tap that
                                         lands on a member's column and does
                                         nothing reads as a broken screen. --}}
                                    <button type="button"
                                            x-on:click="pickDay(@js($day['date']))"
                                            class="space-y-0.5 px-0.5 py-1.5 text-left {{ $rowTint }}"
                                            aria-label="{{ $member->name }}, {{ $day['carbon']->format('l j F') }}"
                                            wire:key="wk-cell-{{ $day['date'] }}-{{ $member->id }}">
                                        {{-- Empty cells stay empty; placeholder text would
                                             be noise repeated 35 times. --}}
                                        @foreach ($cellEvents as $event)
                                            <div class="rounded border-l-2 bg-slate-50 px-1 py-0.5 dark:bg-slate-800/70"
                                                 style="border-color: {{ $member->colour }};">
                                                <span class="block text-[0.7rem] leading-tight font-semibold tabular-nums text-slate-500 dark:text-slate-400">
                                                    {{ $event->all_day ? 'All day' : $event->start_at->timezone($day['tz'])->format('H:i') }}
                                                </span>
                                                <span class="block truncate text-xs leading-tight font-medium">{{ $event->title }}</span>
                                            </div>
                                        @endforeach
                                    </button>
                                @endforeach

                                @if ($this->weekHasHouseholdEvents)
                                    @php $cellEvents = $day['events_by_member'][$this::HOUSEHOLD] ?? collect(); @endphp
                                    <button type="button"
                                            x-on:click="pickDay(@js($day['date']))"
                                            class="space-y-0.5 px-0.5 py-1.5 text-left {{ $rowTint }} {{ $day['is_today'] ? 'rounded-r-lg' : '' }}"
                                            aria-label="Household, {{ $day['carbon']->format('l j F') }}">
                                        @foreach ($cellEvents as $event)
                                            <div class="rounded border-l-2 border-slate-400 bg-slate-50 px-1 py-0.5 dark:bg-slate-800/70">
                                                <span class="block text-[0.7rem] leading-tight font-semibold tabular-nums text-slate-500 dark:text-slate-400">
                                                    {{ $event->all_day ? 'All day' : $event->start_at->timezone($day['tz'])->format('H:i') }}
                                                </span>
                                                <span class="block truncate text-xs leading-tight font-medium">{{ $event->title }}</span>
                                            </div>
                                        @endforeach
                                    </button>
                                @endif
                            @endforeach
                        </div>
                    </div>

                    {{-- Portrait: the same information stacked, day by day, with
                         each member as a sub-group. A seven-column grid on a
                         narrow screen would be unreadable. --}}
                    <div class="pane-scroll h-full min-h-0 rounded-2xl bg-white p-3 lg:hidden dark:bg-slate-900">
                        @foreach ($week as $day)
                            @php $dayIsEmpty = $day['count'] === 0; @endphp

                            <section class="mb-3 last:mb-0" wire:key="wk-stack-{{ $day['date'] }}">
                                <button
                                    type="button"
                                    x-on:click="pickDay(@js($day['date']))"
                                    class="sticky top-0 z-10 flex w-full touch-target items-center gap-2 rounded-lg px-2 text-left {{ $day['is_today'] ? 'bg-blue-50 dark:bg-blue-950/40' : 'bg-white dark:bg-slate-900' }}"
                                >
                                    <span class="text-sm font-semibold tracking-wide uppercase {{ $day['is_today'] ? 'text-blue-600 dark:text-blue-400' : 'text-slate-400' }}">
                                        {{ $day['is_today'] ? 'Today' : $day['carbon']->format('l') }}
                                    </span>
                                    <span class="text-sm text-slate-400">{{ $day['carbon']->format('j M') }}</span>

                                    @foreach ($this->schoolClosures[$day['date']] ?? [] as $closure)
                                        <span class="shrink-0 rounded px-1.5 text-xs font-bold text-white"
                                              style="background-color: {{ $closure->colour() }};">
                                            {{ $closure->badge() }}
                                        </span>
                                    @endforeach

                                    @php $dinner = $this->dinners[$day['date']] ?? null; @endphp
                                    @if ($dinner)
                                        <span class="ml-auto min-w-0 truncate text-sm font-medium text-slate-500 dark:text-slate-400">
                                            {{ $dinner->title }}
                                        </span>
                                    @endif
                                </button>

                                @unless ($dayIsEmpty)
                                    <div class="mt-1 space-y-2 pl-2">
                                        @foreach ($members as $member)
                                            @php $cellEvents = $day['events_by_member'][$member->id] ?? collect(); @endphp
                                            @if ($cellEvents->isNotEmpty())
                                                {{-- The member's whole card, not
                                                     the dot beside their name. --}}
                                                <button type="button"
                                                        x-on:click="pickDay(@js($day['date']))"
                                                        class="w-full text-left"
                                                        aria-label="{{ $member->name }}, {{ $day['carbon']->format('l j F') }}"
                                                        wire:key="wk-stack-{{ $day['date'] }}-{{ $member->id }}">
                                                    <p class="flex items-center gap-1.5 text-xs font-semibold text-slate-500 dark:text-slate-400">
                                                        <span class="size-2 rounded-full" style="background-color: {{ $member->colour }};"></span>
                                                        {{ $member->name }}
                                                    </p>
                                                    <ul class="mt-0.5 space-y-1">
                                                        @foreach ($cellEvents as $event)
                                                            <li class="flex items-baseline gap-2 rounded-lg border-l-2 bg-slate-50 px-2 py-1 dark:bg-slate-800/70"
                                                                style="border-color: {{ $member->colour }};">
                                                                <span class="w-12 shrink-0 text-xs font-semibold tabular-nums text-slate-500 dark:text-slate-400">
                                                                    {{ $event->all_day ? 'All day' : $event->start_at->timezone($day['tz'])->format('H:i') }}
                                                                </span>
                                                                <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ $event->title }}</span>
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                </button>
                                            @endif
                                        @endforeach

                                        @php $householdCell = $day['events_by_member'][$this::HOUSEHOLD] ?? collect(); @endphp
                                        @if ($householdCell->isNotEmpty())
                                            <div>
                                                <p class="flex items-center gap-1.5 text-xs font-semibold text-slate-500 dark:text-slate-400">
                                                    <span class="size-2 rounded-full bg-slate-400"></span>
                                                    Household
                                                </p>
                                                <ul class="mt-0.5 space-y-1">
                                                    @foreach ($householdCell as $event)
                                                        <li class="flex items-baseline gap-2 rounded-lg border-l-2 border-slate-400 bg-slate-50 px-2 py-1 dark:bg-slate-800/70">
                                                            <span class="w-12 shrink-0 text-xs font-semibold tabular-nums text-slate-500 dark:text-slate-400">
                                                                {{ $event->all_day ? 'All day' : $event->start_at->timezone($day['tz'])->format('H:i') }}
                                                            </span>
                                                            <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ $event->title }}</span>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif
                                    </div>
                                @endunless
                            </section>
                        @endforeach
                    </div>
                </div>

                {{-- ===================== ONE DAY ======================== --}}
                @foreach ($days as $day)
                    @php
                        // Only give the household a column on days that need one,
                        // so a normal day is not squeezed by an empty column.
                        $dayHouseholdEvents = $day['events_by_member'][$this::HOUSEHOLD] ?? collect();
                        $dayHouseholdTodos = $this->todosByDate[$day['date']][$this::HOUSEHOLD] ?? collect();
                        $dayNeedsHousehold = $dayHouseholdEvents->isNotEmpty() || $dayHouseholdTodos->isNotEmpty();
                        $dayColumns = $members->count() + ($dayNeedsHousehold ? 1 : 0);
                    @endphp
                    <div x-show="isPicked(@js($day['date']))" class="flex h-full min-h-0 flex-col" x-cloak wire:key="day-{{ $day['date'] }}">
                        <div class="flex shrink-0 justify-end pb-1">
                            <button type="button" x-on:click="showTimeline(@js($day['date']))"
                                    class="touch-target rounded-xl px-3 text-sm font-semibold text-blue-600 dark:text-blue-400">
                                By the hour
                            </button>
                        </div>

                        <div class="grid min-h-0 flex-1 gap-3" style="grid-template-columns: repeat({{ max($dayColumns, 1) }}, minmax(0, 1fr));">
                            @foreach ($members as $member)
                                @php
                                    $memberEvents = $day['events_by_member'][$member->id] ?? collect();
                                    $memberTodos = $this->todosByDate[$day['date']][$member->id] ?? collect();
                                @endphp

                                {{-- The whole column is the door for a child,
                                     header and body alike — the avatar alone
                                     was a nine-millimetre target on a wall
                                     nobody stands close to. Anything inside it
                                     that does its own thing still does it:
                                     the guard lets a chore tile, a link or a
                                     checkbox have the tap first. --}}
                                <div class="flex min-h-0 flex-col overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-slate-900"
                                     @if ($member->is_child)
                                         role="button"
                                         tabindex="0"
                                         aria-label="{{ $member->name }}'s day"
                                         x-on:click="$event.target.closest('button, a, input, label')
                                             || $wire.dispatch('show-my-day', { member: {{ $member->id }}, date: '{{ $day['date'] }}' })"
                                     @endif>
                                    <div class="flex items-center gap-2 px-3 py-2" style="background-color: {{ $member->colour }}1a;">
                                        {{-- A child's avatar is the door into
                                             their own day. An adult's column
                                             header is just a heading. --}}
                                        @if ($member->is_child)
                                            <button
                                                type="button"
                                                wire:click="$dispatch('show-my-day', { member: {{ $member->id }}, date: '{{ $day['date'] }}' })"
                                                {{-- The avatar stays 36px; the button around it is a
                                                     touch target, because this is a wall nobody has a
                                                     mouse for. --}}
                                                class="grid touch-target shrink-0 place-items-center rounded-full"
                                                aria-label="{{ $member->name }}'s day"
                                            >
                                                @if ($member->avatarUrl())
                                                    <img src="{{ $member->avatarUrl() }}" alt="" class="size-9 rounded-full object-cover">
                                                @else
                                                    <span class="grid size-9 place-items-center rounded-full text-sm font-bold text-white"
                                                          style="background-color: {{ $member->colour }};">{{ $member->initials() }}</span>
                                                @endif
                                            </button>
                                        @else
                                            <span class="size-3 shrink-0 rounded-full" style="background-color: {{ $member->colour }};"></span>
                                        @endif
                                        <span class="truncate text-base font-semibold">{{ $member->name }}</span>

                                        {{-- A routine in its window earns a place
                                             in the header; the rest of the day it
                                             is not the column's business. --}}
                                        @php $running = $day['is_today'] ? ($this->runningRoutines[$member->id] ?? null) : null; @endphp

                                        @if ($running)
                                            <button type="button"
                                                    wire:click="$dispatch('show-my-day', { member: {{ $member->id }}, date: '{{ $day['date'] }}' })"
                                                    class="ml-auto shrink-0 rounded-full px-2 py-0.5 text-xs font-bold text-white"
                                                    style="background-color: {{ $member->colour }};">
                                                {{ $running->allDone() ? 'all done' : $running->routine->label().' '.$running->summary() }}
                                            </button>
                                        @elseif ($memberEvents->isNotEmpty())
                                            <span class="ml-auto text-sm text-slate-400">{{ $memberEvents->count() }}</span>
                                        @endif
                                    </div>

                                    <div class="pane-scroll flex-1 space-y-2 p-2">
                                        @forelse ($memberEvents as $event)
                                            <div class="rounded-xl border-l-4 bg-slate-50 p-2.5 dark:bg-slate-800/60"
                                                 style="border-color: {{ $member->colour }};">
                                                <p class="text-sm font-semibold tabular-nums text-slate-500 dark:text-slate-400">
                                                    {{ $event->all_day ? 'All day' : $event->start_at->timezone($day['tz'])->format('H:i') }}
                                                </p>
                                                <p class="mt-0.5 leading-tight font-medium">{{ $event->title }}</p>
                                                @if ($event->location)
                                                    <p class="mt-0.5 truncate text-sm text-slate-500 dark:text-slate-400">{{ $event->location }}</p>
                                                @endif
                                                @if ($event->members->count() > 1)
                                                    <p class="mt-1 truncate text-xs text-slate-400">
                                                        with {{ $event->members->where('id', '!=', $member->id)->pluck('name')->join(', ') }}
                                                    </p>
                                                @endif
                                            </div>
                                        @empty
                                            @if ($memberTodos->isEmpty() && ($this->choresByDate[$day['date']][$member->id] ?? collect())->isEmpty())
                                                <p class="px-1 py-4 text-sm text-slate-400 dark:text-slate-400">Nothing on</p>
                                            @endif
                                        @endforelse

                                        {{-- Today's chores. Tapping the header
                                             opens the big-tap view; these are
                                             here so a glance at the column
                                             shows what is still outstanding. --}}
                                        @php $memberChores = $this->choresByDate[$day['date']][$member->id] ?? collect(); @endphp

                                        @foreach ($memberChores as $slot)
                                            <div class="flex items-center gap-2 rounded-xl px-2 py-1.5 {{ $slot->isDone() ? 'opacity-50' : '' }}"
                                                 style="background-color: {{ $member->colour }}0f;">
                                                <span class="grid size-5 shrink-0 place-items-center rounded-lg border-2 {{ $slot->isDone() ? 'border-transparent text-white' : 'border-slate-300 dark:border-slate-600' }}"
                                                      style="{{ $slot->isDone() ? 'background-color: '.$member->colour.';' : '' }}">
                                                    @if ($slot->isDone())
                                                        <svg class="size-3.5" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 5 5L20 7" /></svg>
                                                    @endif
                                                </span>
                                                <span class="min-w-0 flex-1 truncate text-sm {{ $slot->isDone() ? 'line-through' : 'font-medium' }}">
                                                    @if ($slot->chore->icon) {{ $slot->chore->icon }} @endif{{ $slot->chore->title }}
                                                </span>
                                                @if ($slot->isAwaitingApproval())
                                                    <span class="shrink-0 rounded-full bg-amber-100 px-1.5 text-[0.65rem] font-bold text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">check</span>
                                                @elseif ($slot->points() > 0)
                                                    <span class="shrink-0 text-xs font-bold tabular-nums text-slate-400">{{ $slot->points() }}</span>
                                                @endif
                                            </div>
                                        @endforeach

                                        {{-- To-dos due this day, below the events and
                                             visibly a different kind of thing. --}}
                                        @foreach ($memberTodos as $todo)
                                            <div class="flex items-start gap-2 rounded-xl border border-dashed p-2 dark:border-slate-700"
                                                 style="border-color: {{ $member->colour }}66;">
                                                <svg class="mt-0.5 size-4 shrink-0 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                                                    <rect x="4" y="4" width="16" height="16" rx="3" />
                                                </svg>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block text-[0.7rem] leading-tight font-semibold tracking-wide text-slate-400 uppercase">To do</span>
                                                    <span class="block leading-tight font-medium">{{ $todo->title }}</span>
                                                    <x-todo-due :item="$todo" :today="$this->today" class="text-xs leading-tight" />
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach

                            {{-- Events nobody in particular owns still need somewhere to live. --}}
                            @if ($dayNeedsHousehold)
                                @php $householdEvents = $dayHouseholdEvents; @endphp
                                <div class="flex min-h-0 flex-col overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-slate-900">
                                    <div class="flex items-center gap-2 bg-slate-100 px-3 py-2 dark:bg-slate-800">
                                        <span class="size-3 shrink-0 rounded-full bg-slate-400"></span>
                                        <span class="truncate text-base font-semibold">Household</span>
                                        @if ($householdEvents->isNotEmpty())
                                            <span class="ml-auto text-sm text-slate-400">{{ $householdEvents->count() }}</span>
                                        @endif
                                    </div>

                                    <div class="pane-scroll flex-1 space-y-2 p-2">
                                        @forelse ($householdEvents as $event)
                                            <div class="rounded-xl border-l-4 border-slate-400 bg-slate-50 p-2.5 dark:bg-slate-800/60">
                                                <p class="text-sm font-semibold tabular-nums text-slate-500 dark:text-slate-400">
                                                    {{ $event->all_day ? 'All day' : $event->start_at->timezone($day['tz'])->format('H:i') }}
                                                </p>
                                                <p class="mt-0.5 leading-tight font-medium">{{ $event->title }}</p>
                                                @if ($event->location)
                                                    <p class="mt-0.5 truncate text-sm text-slate-500 dark:text-slate-400">{{ $event->location }}</p>
                                                @endif
                                            </div>
                                        @empty
                                            @if ($dayHouseholdTodos->isEmpty())
                                                <p class="px-1 py-4 text-sm text-slate-400 dark:text-slate-400">Nothing on</p>
                                            @endif
                                        @endforelse

                                        @foreach ($dayHouseholdTodos as $todo)
                                            <div class="flex items-start gap-2 rounded-xl border border-dashed border-slate-300 p-2 dark:border-slate-700">
                                                <svg class="mt-0.5 size-4 shrink-0 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                                                    <rect x="4" y="4" width="16" height="16" rx="3" />
                                                </svg>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block text-[0.7rem] leading-tight font-semibold tracking-wide text-slate-400 uppercase">To do</span>
                                                    <span class="block leading-tight font-medium">{{ $todo->title }}</span>
                                                    <x-todo-due :item="$todo" :today="$this->today" class="text-xs leading-tight" />
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </section>

            {{-- Week strip + upcoming --}}
            <section class="flex min-h-0 flex-col gap-4 lg:col-span-2">
                <div class="flex shrink-0 gap-1.5">
                    {{-- The week segment sits left of Monday and is the default view. --}}
                    <button
                        type="button"
                        data-view="week"
                        x-on:click="showWeek()"
                        class="flex touch-target shrink-0 flex-col items-center justify-center rounded-xl px-3 py-2 transition-colors"
                        :class="view === 'week'
                            ? 'bg-blue-600 text-white'
                            : 'bg-white text-slate-900 dark:bg-slate-900 dark:text-slate-100'"
                    >
                        <span class="text-xs font-medium opacity-70">This</span>
                        <span class="text-sm leading-tight font-bold">week</span>
                    </button>

                    <button
                        type="button"
                        data-view="month"
                        x-on:click="showMonthView()"
                        class="flex touch-target shrink-0 flex-col items-center justify-center rounded-xl px-3 py-2 transition-colors"
                        :class="view === 'month'
                            ? 'bg-blue-600 text-white'
                            : 'bg-white text-slate-900 dark:bg-slate-900 dark:text-slate-100'"
                    >
                        <span class="text-xs font-medium opacity-70">The</span>
                        <span class="text-sm leading-tight font-bold">month</span>
                    </button>

                    <div class="grid min-w-0 flex-1 grid-cols-7 gap-1.5">
                        @foreach ($week as $day)
                            <button
                                type="button"
                                data-date="{{ $day['date'] }}"
                                x-on:click="pickDay(@js($day['date']))"
                                class="flex touch-target flex-col items-center justify-center rounded-xl py-2 transition-colors"
                                :class="isPicked(@js($day['date']))
                                    ? 'bg-blue-600 text-white'
                                    : 'bg-white text-slate-900 dark:bg-slate-900 dark:text-slate-100'"
                            >
                                <span class="text-xs font-medium opacity-70 {{ $day['is_today'] ? 'underline underline-offset-2' : '' }}">
                                    {{ $day['carbon']->format('D') }}
                                </span>
                                <span class="text-lg leading-tight font-bold tabular-nums">{{ $day['carbon']->format('j') }}</span>
                                <span class="mt-0.5 flex h-1.5 items-center gap-0.5">
                                    @foreach ($day['events_by_member']->keys()->take(4) as $bucket)
                                        <span class="size-1.5 rounded-full"
                                              style="background-color: {{ $members->firstWhere('id', $bucket)?->colour ?? '#94a3b8' }};"></span>
                                    @endforeach
                                </span>

                                {{-- Behind the day rather than in it: school
                                     being shut is context for everything else
                                     on that day, not another thing on it. --}}
                                @foreach ($this->schoolClosures[$day['date']] ?? [] as $closure)
                                    <span class="mt-0.5 rounded px-1 text-[0.6rem] font-bold text-white"
                                          style="background-color: {{ $closure->colour() }};"
                                          title="{{ $closure->badge() }}">
                                        {{ $closure->isBankHoliday() ? 'BH' : $closure->code.($closure->isInset() ? ' INSET' : '') }}
                                    </span>
                                @endforeach
                            </button>
                        @endforeach
                    </div>
                </div>

                <div class="pane-scroll min-h-0 flex-1 rounded-2xl bg-white p-3 dark:bg-slate-900">

                    {{-- ------------------- ONE DAY PICKED ------------------- --}}
                    {{-- The rail follows the picked day rather than always
                         counting forward from today. A panel headed "Coming up"
                         listing Tuesday while Monday is selected reads as a
                         bug, whatever it technically means. Rendered per day
                         because the picking is done in Alpine — the events are
                         already loaded, so this costs markup, not queries. --}}
                    @foreach ($week as $day)
                        <div x-show="isPicked(@js($day['date']))" x-cloak wire:key="rail-{{ $day['date'] }}">
                            <h2 class="px-1 pb-2 text-sm font-semibold tracking-wide text-slate-400 uppercase">
                                {{ $day['is_today'] ? 'Today' : $day['carbon']->format('l j F') }}
                            </h2>

                            @forelse ($day['events'] as $event)
                                @php $eventMembers = $event->members; @endphp
                                <div class="flex items-center gap-3 rounded-xl px-1 py-2">
                                    <span class="w-12 shrink-0 text-center text-sm font-semibold tabular-nums text-slate-500 dark:text-slate-400">
                                        {{ $event->all_day ? 'All day' : $event->start_at->timezone($day['tz'])->format('H:i') }}
                                    </span>
                                    <span class="flex h-8 shrink-0 gap-0.5">
                                        @forelse ($eventMembers as $member)
                                            <span class="w-1 rounded-full" style="background-color: {{ $member->colour }};"></span>
                                        @empty
                                            <span class="w-1 rounded-full bg-slate-300 dark:bg-slate-600"></span>
                                        @endforelse
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate font-medium">{{ $event->title }}</span>
                                        <span class="block truncate text-sm text-slate-500 dark:text-slate-400">
                                            {{ $eventMembers->pluck('name')->join(', ') ?: 'Household' }}@if ($event->location) · {{ $event->location }} @endif
                                        </span>
                                    </span>
                                </div>
                            @empty
                                <p class="px-1 py-4 text-sm text-slate-400">Nothing on this day.</p>
                            @endforelse
                        </div>
                    @endforeach

                    {{-- ---------------------- THIS WEEK --------------------- --}}
                    {{-- In the month and timeline views nothing is picked and
                         the week is not on screen, so the rail would be an
                         empty white card the height of the wall. "Coming up"
                         is the right thing to show there. --}}
                    <div x-show="view === 'week' || view === 'month' || view === 'timeline'">
                    <h2 class="px-1 pb-2 text-sm font-semibold tracking-wide text-slate-400 uppercase">Coming up</h2>

                    @forelse ($this->upcoming as $entry)
                        @php
                            $event = $entry['event'];
                            $entryMembers = $event->members;
                        @endphp
                        <button
                            type="button"
                            x-on:click="pickDay(@js($entry['day']->toDateString()))"
                            class="flex w-full touch-target items-center gap-3 rounded-xl px-1 py-2 text-left"
                        >
                            <span class="w-12 shrink-0 text-center">
                                <span class="block text-xs text-slate-400">{{ $entry['day']->format('D') }}</span>
                                <span class="block text-base font-bold tabular-nums">{{ $entry['day']->format('j') }}</span>
                            </span>
                            <span class="flex h-8 shrink-0 gap-0.5">
                                @forelse ($entryMembers as $member)
                                    <span class="w-1 rounded-full" style="background-color: {{ $member->colour }};"></span>
                                @empty
                                    <span class="w-1 rounded-full bg-slate-300 dark:bg-slate-600"></span>
                                @endforelse
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium">{{ $event->title }}</span>
                                <span class="block truncate text-sm text-slate-500 dark:text-slate-400">
                                    {{ $event->all_day ? 'All day' : $event->start_at->timezone($tz)->format('H:i') }}
                                    · {{ $entryMembers->pluck('name')->join(', ') ?: 'Household' }}
                                </span>
                            </span>
                        </button>
                    @empty
                        <p class="px-1 py-4 text-sm text-slate-400">Nothing else coming up.</p>
                    @endforelse
                    </div>
                </div>

                {{-- Household to-dos, under "Coming up" in both views. --}}
                <div class="flex max-h-[40%] min-h-0 shrink-0 flex-col rounded-2xl bg-white p-3 dark:bg-slate-900">
                    <livewire:todos.panel />
                </div>
            </section>
          </div>

          {{-- The fridge door, across the foot of Home. Takes no room at all
               when there is nothing on it, which is most weeks. --}}
          <div class="shrink-0">
              <livewire:notes.board :on-wall="true" />
          </div>
        </div>

        {{-- ---------------------------- LISTS --------------------------- --}}
        <div x-show="tab === 'lists'" x-cloak class="h-full min-h-0">
            <livewire:display.lists :wall-only="true" />
        </div>

        {{-- --------------------------- REVIEW --------------------------- --}}
        <div x-show="tab === 'review'" x-cloak class="h-full min-h-0">
            <livewire:capture.review />
        </div>

        {{-- ----------------------------- MEALS --------------------------- --}}
        <div x-show="tab === 'meals'" x-cloak class="grid h-full min-h-0 gap-4 xl:grid-cols-5">
            <div class="flex min-h-0 flex-col xl:col-span-3">
                <livewire:meals.plan />
            </div>
            <div class="flex min-h-0 flex-col xl:col-span-2">
                {{-- Tonight first: it is the only thing on this tab a child
                     walking past can act on. --}}
                <livewire:meals.tonight />

                <div class="min-h-0 flex-1">
                    <livewire:recipes.box />
                </div>
            </div>
        </div>

        {{-- ------------------------------ HOME ---------------------------- --}}
        <div x-show="tab === 'home-devices'" x-cloak class="h-full min-h-0">
            <livewire:home.panel />
        </div>

        {{-- ------------------- PLACEHOLDERS FOR LATER PHASES ------------ --}}
        @foreach (['photos' => 'Photo library'] as $key => $label)
            <div x-show="tab === '{{ $key }}'" x-cloak class="grid h-full place-items-center">
                <div class="text-center">
                    <p class="text-xl font-semibold text-slate-400">{{ $label }}</p>
                    <p class="mt-1 text-sm text-slate-400 dark:text-slate-400">Arrives in a later phase.</p>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Search, as an overlay rather than a permanent field: the header has
         no room for one, and on a wall it is asked for rarely and deliberately. --}}
    <div x-show="searching" x-cloak
         x-on:wall-tab.window="tab = $event.detail.tab; searching = false"
         x-on:keydown.escape.window="searching = false">
        <x-modal close-on="searching = false" label="Search" width="max-w-2xl">
            <div class="p-4">
                <livewire:search.box :on-wall="true" />
            </div>
        </x-modal>
    </div>

    {{-- Cooking. Not a dialog over the wall — the wall, for as long as
         somebody is at the hob. --}}
    <livewire:recipes.cook />

    {{-- A child's day, and the keypad that guards the two things it should. --}}
    <livewire:kids.my-day />
    <livewire:kids.ledger />
    <livewire:kids.pin />

    {{-- ============================ TAB BAR ============================= --}}
    <nav data-tab-bar class="grid shrink-0 grid-cols-6 gap-1 border-t border-slate-200 px-4 py-1 dark:border-slate-800">
        @foreach ([
            ['home', 'Home', 'M3 10.5 12 3l9 7.5V21a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z'],
            ['review', 'Review', 'M4 4h16v12H8l-4 4z'],
            ['meals', 'Meals', 'M6 3v9a3 3 0 0 0 6 0V3M9 12v9M17 3c-1.5 2-2 4-2 6s.5 3 2 3 2-1 2-3-.5-4-2-6zm0 9v9'],
            ['lists', 'Lists', 'M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01'],
            ['home-devices', 'Switches', 'M9 21h6M10 18h4M12 3a6 6 0 0 0-3.5 10.9c.3.2.5.6.5 1V15h6v-.1c0-.4.2-.8.5-1A6 6 0 0 0 12 3z'],
            ['photos', 'Photos', 'M3 7a2 2 0 0 1 2-2h3l1.5-2h5L16 5h3a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z M12 16a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z'],
        ] as [$key, $label, $path])
            <button
                type="button"
                x-on:click="tab = '{{ $key }}'"
                {{-- Taller than the 44px minimum: this is a 15.6" panel on a
                     wall, tapped in passing rather than looked at first. --}}
                class="flex min-h-14 flex-col items-center justify-center gap-1 rounded-xl"
                :class="tab === '{{ $key }}' ? 'text-blue-600 dark:text-blue-400' : 'text-slate-400'"
                @if ($key === 'review' && $this->reviewCount > 0)
                    aria-label="{{ $label }}, {{ $this->reviewCount }} waiting"
                @endif
            >
                <span class="relative">
                    <svg class="size-[1.875rem]" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="{{ $path }}" />
                    </svg>
                    @if ($key === 'review' && $this->reviewCount > 0)
                        {{-- Hidden from the accessible name: the button already
                             says "Review, N waiting". --}}
                        <span aria-hidden="true"
                              class="absolute -top-1 -right-2.5 grid min-w-5 place-items-center rounded-full bg-blue-600 px-1.5 text-xs font-bold text-white">
                            {{ $this->reviewCount }}
                        </span>
                    @endif
                </span>
                <span class="text-[0.9375rem] font-medium">{{ $label }}</span>
            </button>
        @endforeach
    </nav>

    {{-- ========================== SCREENSAVER =========================== --}}
    {{-- Any touch wakes it, wherever it lands: on a dark screen there is
         nothing to aim at, so everything is the target. --}}
    <div
        x-ref="screensaver"
        x-show="idle"
        x-cloak
        x-transition.opacity.duration.700ms
        x-on:click="wake()"
        x-on:touchstart="wake()"
        class="fixed inset-0 z-50 overflow-hidden bg-black"
    >
        {{-- The photographs, two layers deep so one fades into the next. --}}
        <template x-if="saverStyle === 'photos' && photos.length">
            <div class="h-full w-full">
                <img :src="slotA" alt="" x-show="slotA" x-cloak
                     class="absolute inset-0 h-full w-full object-cover transition-opacity duration-[1500ms]"
                     :class="showA ? 'opacity-100' : 'opacity-0'">
                <img :src="slotB" alt="" x-show="slotB" x-cloak
                     class="absolute inset-0 h-full w-full object-cover transition-opacity duration-[1500ms]"
                     :class="showA ? 'opacity-0' : 'opacity-100'">
            </div>
        </template>

        @if ($wall['style'] === 'photos')
            @php $agenda = $this->saverAgenda; @endphp

            {{-- Over a photograph nothing drifts: the picture changes every
                 twenty seconds, which is all the burn-in protection a panel
                 needs, and a wandering clock over a face is just restless.

                 Each corner carries its own gradient rather than one over the
                 whole frame — a photograph should still look like a
                 photograph in the middle. --}}

            {{-- Weather, top right. --}}
            @if ($this->weather)
                <div class="absolute inset-x-0 top-0 bg-gradient-to-b from-black/70 to-transparent p-10 text-white">
                    <p x-ref="saverWeather"
                       class="display-face ml-auto flex w-fit items-center gap-3 text-3xl drop-shadow will-change-transform"
                       :style="`transform: translate3d(${driftWeather.x}px, ${driftWeather.y}px, 0)`"
                       style="transition: transform 200ms linear;">
                        <x-icon :name="$this->weather->icon()" class="size-9 shrink-0" />
                        <span>{{ $this->weather->description() }}</span>
                        <span class="tabular-nums font-semibold">{{ $this->weather->round($this->weather->temperature) }}&deg;</span>
                    </p>
                </div>
            @endif

            <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/85 via-black/60 to-transparent pt-24">
                {{-- items-end, and the clock is the tall one: the events sit
                     on the same baseline and are pushed out of its way by the
                     flex rather than by anything having to know its height. --}}
                <div class="flex items-end justify-between gap-12 p-10">
                    {{-- Clock, bottom left. --}}
                    <div x-ref="saverClock"
                         class="shrink-0 text-white drop-shadow will-change-transform"
                         :style="`transform: translate3d(${driftClock.x}px, ${driftClock.y}px, 0)`"
                         style="transition: transform 200ms linear;">
                        <p class="display-digits text-[18rem] leading-[0.85] font-bold" x-text="clock"></p>
                        <p class="display-face mt-4 text-3xl text-white/85"
                           x-text="now.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', timeZone: tz })"></p>

                        {{-- The caption of whichever photograph is showing. --}}
                        <p x-show="(showA ? captionA : captionB)" x-cloak
                           x-text="showA ? captionA : captionB"
                           class="mt-3 max-w-xl text-xl text-white/80"></p>
                    </div>

                    {{-- Today, bottom right. --}}
                    <div x-ref="saverEvents"
                         class="display-face min-w-0 max-w-2xl text-right text-white drop-shadow will-change-transform"
                         :style="`transform: translate3d(${driftEvents.x}px, ${driftEvents.y}px, 0)`"
                         style="transition: transform 200ms linear;">
                        @if ($agenda['label'])
                            <p class="mb-1 text-xl font-semibold tracking-wide text-white/75 uppercase">
                                {{ $agenda['label'] }}
                            </p>
                        @endif

                        @forelse ($agenda['events'] as $line)
                            <p class="flex items-baseline justify-end gap-4 py-0.5 text-2xl leading-snug"
                               wire:key="psaver-{{ $line['key'] }}">
                                <span class="min-w-0 truncate font-semibold text-white/95">{{ $line['title'] }}</span>
                                <span class="w-24 shrink-0 tabular-nums text-white/85">{{ $line['when'] }}</span>
                                <span class="flex w-24 shrink-0 items-baseline justify-end gap-2">
                                    @forelse ($line['who'] as $person)
                                        <span class="flex items-baseline gap-1.5">
                                            <span class="inline-block size-2.5 shrink-0 translate-y-[-0.15em] rounded-full"
                                                  style="background-color: {{ $person['colour'] ?: '#94a3b8' }};"
                                                  aria-hidden="true"></span>
                                            <span class="text-white/85">{{ $person['initials'] }}</span>
                                        </span>
                                    @empty
                                        <span class="flex items-baseline gap-1.5">
                                            <span class="inline-block size-2.5 shrink-0 translate-y-[-0.15em] rounded-full bg-white/70"
                                                  aria-hidden="true"></span>
                                            <span class="text-white/75">All</span>
                                        </span>
                                    @endforelse
                                    @if ($line['extra_people'] > 0)
                                        <span class="text-white/75">+{{ $line['extra_people'] }}</span>
                                    @endif
                                </span>
                            </p>
                        @empty
                            <p class="text-2xl text-white/75">Nothing on tomorrow either.</p>
                        @endforelse

                        @if ($agenda['more'] > 0)
                            <p class="pt-0.5 text-xl text-white/75">+{{ $agenda['more'] }} more</p>
                        @endif
                    </div>
                </div>
            </div>
        @else
            {{-- A clock that wanders, so the same numerals never sit in the
                 same pixels all night. Positioned rather than centred: the
                 drift is a translate within the room it has. --}}
            <div x-ref="driftingClock"
                 class="display-face absolute top-0 left-0 p-10 text-white will-change-transform"
                 :style="`transform: translate3d(${drift.x}px, ${drift.y}px, 0)`"
                 style="transition: transform 200ms linear;">
                <p class="display-digits text-[18rem] leading-[0.85] font-bold" x-text="clock"></p>
                <p class="display-face mt-4 text-3xl text-white/60"
                   x-text="now.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', timeZone: tz })"></p>

                @if ($wall['style'] === 'today')
                    @php $agenda = $this->saverAgenda; @endphp

                    <div class="mt-8 max-w-4xl">
                        @if ($agenda['label'])
                            <p class="mb-2 text-2xl font-semibold tracking-wide text-white/45 uppercase">
                                {{ $agenda['label'] }}
                            </p>
                        @endif

                        @forelse ($agenda['events'] as $line)
                            {{-- Colour dot, initials, time, title. Sized to be
                                 read from the other side of a kitchen, which
                                 is the only place anybody reads this from. --}}
                            <p class="flex items-baseline gap-4 py-1 text-3xl leading-snug"
                               wire:key="saver-{{ $line['key'] }}">
                                {{-- Fixed width: "PT" and "All" are different
                                     lengths, and a ragged left edge makes six
                                     lines read as six separate things. --}}
                                <span class="flex w-28 shrink-0 items-baseline gap-2">
                                    @forelse ($line['who'] as $person)
                                        <span class="flex items-baseline gap-1.5">
                                            <span class="inline-block size-3 shrink-0 translate-y-[-0.15em] rounded-full"
                                                  style="background-color: {{ $person['colour'] ?: '#94a3b8' }};"
                                                  aria-hidden="true"></span>
                                            <span class="text-white/60">{{ $person['initials'] }}</span>
                                        </span>
                                    @empty
                                        <span class="flex items-baseline gap-1.5">
                                            <span class="inline-block size-3 shrink-0 translate-y-[-0.15em] rounded-full bg-white/35"
                                                  aria-hidden="true"></span>
                                            <span class="text-white/45">All</span>
                                        </span>
                                    @endforelse
                                    @if ($line['extra_people'] > 0)
                                        <span class="text-white/40">+{{ $line['extra_people'] }}</span>
                                    @endif
                                </span>

                                <span class="w-28 shrink-0 tabular-nums text-white/60">{{ $line['when'] }}</span>
                                <span class="min-w-0 flex-1 truncate font-semibold text-white/90">{{ $line['title'] }}</span>
                            </p>
                        @empty
                            <p class="text-3xl text-white/40">Nothing on tomorrow either.</p>
                        @endforelse

                        @if ($agenda['more'] > 0)
                            <p class="pt-1 text-2xl text-white/40">+{{ $agenda['more'] }} more</p>
                        @endif

                        @if ($this->weather)
                            <p class="mt-5 flex items-center gap-3 text-3xl text-white/60">
                                <x-icon :name="$this->weather->icon()" class="size-9 shrink-0" />
                                <span>{{ $this->weather->description() }}</span>
                                <span class="tabular-nums">{{ $this->weather->round($this->weather->temperature) }}&deg;</span>
                            </p>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </div>
</div>
