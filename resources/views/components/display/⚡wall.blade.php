<?php

use App\Models\ChecklistItem;
use App\Models\Event;
use App\Models\Household;
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

        $events = Event::query()
            ->notCancelled()
            ->overlapping($from, $to)
            ->whereHas('calendar', fn ($q) => $q->where('is_visible', true))
            // Eager loaded so bucketing below never touches the database again.
            ->with(['calendar', 'members'])
            ->orderBy('all_day', 'desc')
            ->orderBy('start_at')
            ->get();

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
        return $this->household()->todayLocal()->toDateString();
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
            ->whereNotNull('due_on')
            ->whereBetween('due_on', [$from->toDateString(), $to->toDateString()])
            ->with('member')
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

    #[Computed]
    public function checklists(): Collection
    {
        return $this->household()->checklists()->with('items')->get();
    }

    /** @return list<string> */
    #[Computed]
    public function photos(): array
    {
        return app(PhotoLibrary::class)->urls();
    }
}; ?>

@php
    $members = $this->members;
    $days = $this->days;
    $week = $this->week;
    $tz = $this->household()->displayTimezone();
    $idleMs = config('familyhub.screensaver.idle_minutes') * 60 * 1000;
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
        now: new Date(),
        idle: false,
        photoIndex: 0,
        photos: @js($this->photos),
        idleMs: @js($idleMs),
        tz: @js($tz),
        weekDates: @js(array_column($week, 'date')),

        init() {
            setInterval(() => (this.now = new Date()), 1000);
            this.armIdleTimer();

            if (this.photos.length > 1) {
                setInterval(() => {
                    if (this.idle) this.photoIndex = (this.photoIndex + 1) % this.photos.length;
                }, @js(config('familyhub.screensaver.interval_seconds') * 1000));
            }
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

        armIdleTimer() {
            if (!this.idleMs) return;
            clearTimeout(this.idleTimer);
            this.idleTimer = setTimeout(() => (this.idle = true), this.idleMs);
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
            <span class="text-4xl font-bold tabular-nums" x-text="clock">&nbsp;</span>

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
        </div>

        <div class="text-right">
            <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ $this->household()->name }}</p>
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
    </header>

    {{-- ============================== BODY ============================== --}}
    <div class="min-h-0 flex-1 px-6 pb-3 sm:px-8">

        {{-- ---------------------------- HOME ---------------------------- --}}
        <div x-show="tab === 'home'" class="grid h-full min-h-0 gap-4 lg:grid-cols-5">

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

                <div x-show="view === 'week'" class="h-full min-h-0" x-cloak>

                    {{-- Landscape: a member-by-day grid. Sized to fit five
                         columns without sideways scrolling on an iPad. --}}
                    <div class="pane-scroll hidden h-full min-h-0 rounded-2xl bg-white p-2 lg:block dark:bg-slate-900">
                        <div class="grid gap-x-1"
                             style="grid-template-columns: 3.5rem repeat({{ max($weekColumns, 1) }}, minmax(0, 1fr));">

                            {{-- Header row --}}
                            <div class="sticky top-0 z-10 bg-white dark:bg-slate-900"></div>
                            @foreach ($members as $member)
                                <div class="sticky top-0 z-10 flex items-center gap-1.5 bg-white px-1.5 pb-1 dark:bg-slate-900"
                                     wire:key="wk-head-{{ $member->id }}">
                                    <span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $member->colour }};"></span>
                                    <span class="truncate text-sm font-semibold">{{ $member->name }}</span>
                                </div>
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
                                    <div class="space-y-0.5 px-0.5 py-1.5 {{ $rowTint }}"
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
                                    </div>
                                @endforeach

                                @if ($this->weekHasHouseholdEvents)
                                    @php $cellEvents = $day['events_by_member'][$this::HOUSEHOLD] ?? collect(); @endphp
                                    <div class="space-y-0.5 px-0.5 py-1.5 {{ $rowTint }} {{ $day['is_today'] ? 'rounded-r-lg' : '' }}">
                                        @foreach ($cellEvents as $event)
                                            <div class="rounded border-l-2 border-slate-400 bg-slate-50 px-1 py-0.5 dark:bg-slate-800/70">
                                                <span class="block text-[0.7rem] leading-tight font-semibold tabular-nums text-slate-500 dark:text-slate-400">
                                                    {{ $event->all_day ? 'All day' : $event->start_at->timezone($day['tz'])->format('H:i') }}
                                                </span>
                                                <span class="block truncate text-xs leading-tight font-medium">{{ $event->title }}</span>
                                            </div>
                                        @endforeach
                                    </div>
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
                                </button>

                                @unless ($dayIsEmpty)
                                    <div class="mt-1 space-y-2 pl-2">
                                        @foreach ($members as $member)
                                            @php $cellEvents = $day['events_by_member'][$member->id] ?? collect(); @endphp
                                            @if ($cellEvents->isNotEmpty())
                                                <div wire:key="wk-stack-{{ $day['date'] }}-{{ $member->id }}">
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
                                                </div>
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
                    <div x-show="isPicked(@js($day['date']))" class="h-full min-h-0" x-cloak wire:key="day-{{ $day['date'] }}">
                        <div class="grid h-full min-h-0 gap-3" style="grid-template-columns: repeat({{ max($dayColumns, 1) }}, minmax(0, 1fr));">
                            @foreach ($members as $member)
                                @php
                                    $memberEvents = $day['events_by_member'][$member->id] ?? collect();
                                    $memberTodos = $this->todosByDate[$day['date']][$member->id] ?? collect();
                                @endphp

                                <div class="flex min-h-0 flex-col overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-slate-900">
                                    <div class="flex items-center gap-2 px-3 py-2" style="background-color: {{ $member->colour }}1a;">
                                        <span class="size-3 shrink-0 rounded-full" style="background-color: {{ $member->colour }};"></span>
                                        <span class="truncate text-base font-semibold">{{ $member->name }}</span>
                                        @if ($memberEvents->isNotEmpty())
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
                                            @if ($memberTodos->isEmpty())
                                                <p class="px-1 py-4 text-sm text-slate-400 dark:text-slate-600">Nothing on</p>
                                            @endif
                                        @endforelse

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
                                                <p class="px-1 py-4 text-sm text-slate-400 dark:text-slate-600">Nothing on</p>
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
                            </button>
                        @endforeach
                    </div>
                </div>

                <div class="pane-scroll min-h-0 flex-1 rounded-2xl bg-white p-3 dark:bg-slate-900">
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

                {{-- Household to-dos, under "Coming up" in both views. --}}
                <div class="flex max-h-[40%] min-h-0 shrink-0 flex-col rounded-2xl bg-white p-3 dark:bg-slate-900">
                    <livewire:todos.panel />
                </div>
            </section>
        </div>

        {{-- ---------------------------- LISTS --------------------------- --}}
        <div x-show="tab === 'lists'" x-cloak class="h-full min-h-0">
            <livewire:display.lists />
        </div>

        {{-- ------------------- PLACEHOLDERS FOR LATER PHASES ------------ --}}
        @foreach (['chores' => 'Chores, routines and stars', 'meals' => 'Meal plan and recipes', 'photos' => 'Photo library'] as $key => $label)
            <div x-show="tab === '{{ $key }}'" x-cloak class="grid h-full place-items-center">
                <div class="text-center">
                    <p class="text-xl font-semibold text-slate-400">{{ $label }}</p>
                    <p class="mt-1 text-sm text-slate-400 dark:text-slate-600">Arrives in a later phase.</p>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ============================ TAB BAR ============================= --}}
    <nav class="grid shrink-0 grid-cols-5 gap-1 border-t border-slate-200 px-4 py-1.5 dark:border-slate-800">
        @foreach ([
            ['home', 'Home', 'M3 10.5 12 3l9 7.5V21a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z'],
            ['chores', 'Chores', 'M9 11.5 11.5 14 16 8.5M6 3h12a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z'],
            ['meals', 'Meals', 'M6 3v9a3 3 0 0 0 6 0V3M9 12v9M17 3c-1.5 2-2 4-2 6s.5 3 2 3 2-1 2-3-.5-4-2-6zm0 9v9'],
            ['lists', 'Lists', 'M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01'],
            ['photos', 'Photos', 'M3 7a2 2 0 0 1 2-2h3l1.5-2h5L16 5h3a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z M12 16a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z'],
        ] as [$key, $label, $path])
            <button
                type="button"
                x-on:click="tab = '{{ $key }}'"
                class="flex touch-target flex-col items-center justify-center gap-0.5 rounded-xl py-1"
                :class="tab === '{{ $key }}' ? 'text-blue-600 dark:text-blue-400' : 'text-slate-400'"
            >
                <svg class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="{{ $path }}" />
                </svg>
                <span class="text-xs font-medium">{{ $label }}</span>
            </button>
        @endforeach
    </nav>

    {{-- ========================== SCREENSAVER =========================== --}}
    <div
        x-show="idle"
        x-cloak
        x-transition.opacity.duration.700ms
        x-on:click="wake()"
        class="fixed inset-0 z-50 grid place-items-center bg-black"
    >
        <template x-if="photos.length">
            <img :src="photos[photoIndex]" alt="" class="h-full w-full object-cover">
        </template>

        {{-- With no photos loaded the wall becomes a large, quiet clock. --}}
        <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent p-10 text-white">
            <p class="text-7xl font-bold tabular-nums" x-text="clock"></p>
            <p class="mt-1 text-xl text-white/70"
               x-text="now.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', timeZone: tz })"></p>
        </div>
    </div>
</div>
