<?php

use App\Models\Event;
use App\Models\Household;
use App\Services\PhotoLibrary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The wall-mounted display.
 *
 * Everything for the next fortnight is rendered server-side in one pass and
 * then shown or hidden by Alpine, so changing day or tab costs no round trip.
 * Livewire re-renders on a slow poll to pick up edits made from phones.
 */
new #[Layout('layouts::display')] class extends Component
{
    /** How far ahead the display holds data, in days. */
    public const HORIZON = 14;

    public function household(): Household
    {
        return Household::current();
    }

    #[Computed]
    public function members(): Collection
    {
        return $this->household()->members()->get();
    }

    /**
     * The fortnight, each day carrying its events bucketed by member.
     *
     * @return list<array{date: string, carbon: Carbon, is_today: bool, events_by_member: array<int|string, Collection>, count: int}>
     */
    #[Computed]
    public function days(): array
    {
        $tz = $this->household()->displayTimezone();

        // Day boundaries are the family's midnights, not the server's. An event
        // at 00:30 London is 23:30 UTC the previous day; bucketing in UTC would
        // file it under the wrong day on the wall.
        $from = $this->household()->todayLocal();
        $to = $from->addDays(self::HORIZON);

        $events = Event::query()
            ->notCancelled()
            ->overlapping($from, $to)
            ->whereHas('calendar', fn ($q) => $q->where('is_visible', true))
            // Eager loaded so bucketing below never touches the database again.
            ->with('calendar.member')
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
            );

            $days[] = [
                'date' => $day->toDateString(),
                'carbon' => $day,
                'tz' => $tz,
                'is_today' => $i === 0,
                'count' => $onThisDay->count(),
                'events_by_member' => $onThisDay->groupBy(
                    fn (Event $e) => $e->calendar?->member_id ?? 'unassigned'
                ),
            ];
        }

        return $days;
    }

    /** The next fortnight's events as a flat list, for the "upcoming" rail. */
    #[Computed]
    public function upcoming(): Collection
    {
        return collect($this->days())
            ->skip(1)
            ->flatMap(fn (array $day) => $day['events_by_member']->flatten()->map(
                fn (Event $e) => ['event' => $e, 'day' => $day['carbon']]
            ))
            ->take(12)
            ->values();
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
    $idleMs = config('familyhub.screensaver.idle_minutes') * 60 * 1000;
@endphp

<div
    class="app-shell flex flex-col text-slate-900 dark:text-slate-100"
    wire:poll.60s
    x-data="{
        /* --- local state: never round-trips to the server --- */
        selected: @js($days[0]['date']),
        tab: 'home',
        now: new Date(),
        idle: false,
        photoIndex: 0,
        photos: @js($this->photos),
        idleMs: @js($idleMs),
        dates: @js(array_column($days, 'date')),

        init() {
            setInterval(() => (this.now = new Date()), 1000);
            this.armIdleTimer();

            if (this.photos.length > 1) {
                setInterval(() => {
                    if (this.idle) this.photoIndex = (this.photoIndex + 1) % this.photos.length;
                }, @js(config('familyhub.screensaver.interval_seconds') * 1000));
            }
        },

        /* Any touch anywhere wakes the display and restarts the countdown. */
        armIdleTimer() {
            if (!this.idleMs) return;
            clearTimeout(this.idleTimer);
            this.idleTimer = setTimeout(() => (this.idle = true), this.idleMs);
        },

        wake() {
            this.idle = false;
            this.armIdleTimer();
        },

        /* Swiping the agenda moves a day at a time, clamped to the horizon. */
        shiftDay(delta) {
            const at = this.dates.indexOf(this.selected);
            const next = Math.min(Math.max(at + delta, 0), this.dates.length - 1);
            this.selected = this.dates[next];
        },

        tz: @js($this->household()->displayTimezone()),

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
            <div>
                <p class="text-xl font-semibold" x-text="new Date(selected + 'T00:00:00').toLocaleDateString('en-GB', { weekday: 'long' })"></p>
                <p class="text-sm text-slate-500 dark:text-slate-400"
                   x-text="new Date(selected + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'long' })"></p>
            </div>
        </div>

        <div class="text-right">
            <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ $this->household()->name }}</p>
            <button
                type="button"
                x-show="selected !== dates[0]"
                x-on:click="selected = dates[0]"
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

            {{-- Agenda: colour-coded column per member --}}
            <section
                class="min-h-0 lg:col-span-3"
                x-on:touchstart="$data.swipeFrom = $event.changedTouches[0].clientX"
                x-on:touchend="
                    const dx = $event.changedTouches[0].clientX - ($data.swipeFrom ?? 0);
                    if (Math.abs(dx) > 60) shiftDay(dx < 0 ? 1 : -1);
                "
            >
                @foreach ($days as $day)
                    <div x-show="selected === @js($day['date'])" class="h-full min-h-0" x-cloak>
                        <div class="grid h-full min-h-0 gap-3" style="grid-template-columns: repeat({{ max($members->count(), 1) }}, minmax(0, 1fr));">
                            @foreach ($members as $member)
                                @php $memberEvents = $day['events_by_member'][$member->id] ?? collect(); @endphp

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
                                            </div>
                                        @empty
                                            <p class="px-1 py-4 text-sm text-slate-400 dark:text-slate-600">Nothing on</p>
                                        @endforelse
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </section>

            {{-- Week strip + upcoming --}}
            <section class="flex min-h-0 flex-col gap-4 lg:col-span-2">
                <div class="grid shrink-0 grid-cols-7 gap-1.5">
                    @foreach (array_slice($days, 0, 7) as $day)
                        <button
                            type="button"
                            x-on:click="selected = @js($day['date'])"
                            class="touch-target flex flex-col items-center justify-center rounded-xl py-2 transition-colors"
                            :class="selected === @js($day['date'])
                                ? 'bg-blue-600 text-white'
                                : 'bg-white text-slate-900 dark:bg-slate-900 dark:text-slate-100'"
                        >
                            <span class="text-xs font-medium opacity-70">{{ $day['carbon']->format('D') }}</span>
                            <span class="text-lg leading-tight font-bold tabular-nums">{{ $day['carbon']->format('j') }}</span>
                            <span class="mt-0.5 flex h-1.5 items-center gap-0.5">
                                @foreach ($day['events_by_member']->keys()->take(4) as $memberId)
                                    <span class="size-1.5 rounded-full"
                                          style="background-color: {{ $members->firstWhere('id', $memberId)?->colour ?? '#94a3b8' }};"></span>
                                @endforeach
                            </span>
                        </button>
                    @endforeach
                </div>

                <div class="pane-scroll min-h-0 flex-1 rounded-2xl bg-white p-3 dark:bg-slate-900">
                    <h2 class="px-1 pb-2 text-sm font-semibold tracking-wide text-slate-400 uppercase">Coming up</h2>

                    @forelse ($this->upcoming as $entry)
                        @php
                            $event = $entry['event'];
                            $colour = $event->calendar?->member?->colour ?? '#94a3b8';
                        @endphp
                        <button
                            type="button"
                            x-on:click="selected = @js($entry['day']->toDateString())"
                            class="flex w-full touch-target items-center gap-3 rounded-xl px-1 py-2 text-left"
                        >
                            <span class="w-12 shrink-0 text-center">
                                <span class="block text-xs text-slate-400">{{ $entry['day']->format('D') }}</span>
                                <span class="block text-base font-bold tabular-nums">{{ $entry['day']->format('j') }}</span>
                            </span>
                            <span class="h-8 w-1 shrink-0 rounded-full" style="background-color: {{ $colour }};"></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium">{{ $event->title }}</span>
                                <span class="block text-sm text-slate-500 dark:text-slate-400">
                                    {{ $event->all_day ? 'All day' : $event->start_at->timezone($this->household()->displayTimezone())->format('H:i') }}
                                    @if ($event->calendar?->member) · {{ $event->calendar->member->name }} @endif
                                </span>
                            </span>
                        </button>
                    @empty
                        <p class="px-1 py-4 text-sm text-slate-400">Nothing else this fortnight.</p>
                    @endforelse
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
