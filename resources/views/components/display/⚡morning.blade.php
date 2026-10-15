<?php

use App\Models\Event;
use App\Models\Household;
use App\Models\Member;
use App\Services\Calendar\EventWindow;
use App\Services\Chores\ChoreBoard;
use App\Services\Chores\ChoreSlot;
use App\Services\Display\WallFace;
use App\Services\Routines\RoutineBoard;
use App\Services\Routines\RoutineProgress;
use App\Services\Weather\Forecast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The morning face.
 *
 * Between six and nine a kitchen asks three questions and no others: what is
 * on today, has everybody done the things they do before school, and is
 * there anything that has to go in a bag. Everything else the wall knows can
 * wait until nine.
 *
 * Weekdays only — the wall uses the day face at the weekend, because nobody
 * needs a PE kit reminder on a Saturday.
 */
new class extends Component
{
    protected function household(): Household
    {
        return Household::current();
    }

    #[Computed]
    public function today(): CarbonImmutable
    {
        return $this->household()->todayLocal();
    }

    #[Computed]
    public function greeting(): string
    {
        return WallFace::GREETINGS[WallFace::MORNING];
    }

    /* ------------------------------ weather ----------------------------- */

    #[Computed]
    public function weather(): ?Forecast
    {
        return app(\App\Services\Weather\OpenMeteo::class)->current();
    }

    /** What it will be doing at the school gate. */
    #[Computed]
    public function pickupWeather(): ?Forecast
    {
        return app(\App\Services\Weather\OpenMeteo::class)
            ->atHour($this->household()->schoolPickupHour());
    }

    #[Computed]
    public function pickupLabel(): string
    {
        return CarbonImmutable::today()->setTime($this->household()->schoolPickupHour(), 0)->format('H:i');
    }

    /* ------------------------------ members ----------------------------- */

    /** @return Collection<int, Member> */
    #[Computed]
    public function members(): Collection
    {
        return $this->household()->members()->get();
    }

    /**
     * Today's events, in a column per person.
     *
     * The same bucketing the week grid uses, so a morning and a Wednesday
     * afternoon show the same event under the same name in the same colour.
     *
     * @return Collection<int|string, Collection<int, Event>>
     */
    #[Computed]
    public function eventsByMember(): Collection
    {
        $today = $this->today;

        $events = app(EventWindow::class)
            ->between($this->household(), $today->startOfDay(), $today->endOfDay())
            ->sortBy([['all_day', 'desc'], ['start_at', 'asc']])
            ->values();

        $buckets = collect();

        foreach ($events as $event) {
            $ids = $event->members->pluck('id');

            if ($ids->isEmpty()) {
                $buckets->put('household', ($buckets->get('household') ?? collect())->push($event));

                continue;
            }

            foreach ($ids as $id) {
                $buckets->put($id, ($buckets->get($id) ?? collect())->push($event));
            }
        }

        return $buckets;
    }

    /* ------------------------- routines and chores ----------------------- */

    /**
     * The routine each child is in the middle of, if any.
     *
     * @return Collection<int, RoutineProgress>
     */
    #[Computed]
    public function routines(): Collection
    {
        return app(RoutineBoard::class)
            ->activeAcross($this->household(), $this->today, $this->household()->nowLocal())
            ->keyBy(fn (RoutineProgress $progress) => $progress->routine->member_id);
    }

    /**
     * Today's chores, by member.
     *
     * @return Collection<int|string, Collection<int, ChoreSlot>>
     */
    #[Computed]
    public function chores(): Collection
    {
        return app(ChoreBoard::class)
            ->forDay($this->household(), $this->today)
            ->groupBy(fn (ChoreSlot $slot) => $slot->chore->member_id ?? 'household');
    }

    /**
     * What each child needs in a bag today.
     *
     * @return Collection<int, list<string>>
     */
    #[Computed]
    public function needed(): Collection
    {
        return $this->members
            ->mapWithKeys(fn (Member $member) => [$member->id => $member->neededOn($this->today)])
            ->filter(fn (array $items) => $items !== []);
    }

    /* ------------------------------ ticking ----------------------------- */

    public function tickStep(int $step): void
    {
        $routineStep = \App\Models\RoutineStep::with('routine')->find($step);

        if (! $routineStep || $routineStep->routine?->household_id !== $this->household()->id) {
            return;
        }

        app(RoutineBoard::class)->complete($routineStep, $this->today);

        unset($this->routines);

        $this->dispatch('routines-changed');
    }

    public function tickChore(int $chore): void
    {
        $model = \App\Models\Chore::where('household_id', $this->household()->id)->find($chore);

        if (! $model) {
            return;
        }

        app(ChoreBoard::class)->complete($model, $this->today, $model->member);

        unset($this->chores);

        $this->dispatch('chores-changed');
    }

    #[On('routines-changed')]
    public function refreshRoutines(): void
    {
        unset($this->routines);
    }

    #[On('chores-changed')]
    public function refreshChores(): void
    {
        unset($this->chores);
    }
}; ?>

@php
    $members = $this->members;
    $tz = $this->household()->displayTimezone();
@endphp

<div class="flex h-full min-h-0 flex-col gap-6" data-face="morning">
    {{-- The two forecasts. Now, and the one moment in the day where knowing
         changes what goes in a bag. --}}
    <div class="flex shrink-0 flex-wrap items-center gap-10">
        @if ($this->weather)
            <div class="flex items-center gap-4">
                <x-icon :name="$this->weather->icon()" class="size-14 shrink-0" />
                <div>
                    <p class="text-[2.75rem] font-semibold leading-none tabular-nums">
                        {{ $this->weather->round($this->weather->temperature) }}&deg;
                    </p>
                    <p class="text-[1.0625rem] font-medium uppercase tracking-[0.06em] opacity-70">Now</p>
                </div>
            </div>
        @endif

        @if ($this->pickupWeather)
            <div class="flex items-center gap-4">
                <x-icon :name="$this->pickupWeather->icon()" class="size-14 shrink-0 opacity-80" />
                <div>
                    <p class="text-[2.75rem] font-semibold leading-none tabular-nums">
                        {{ $this->pickupWeather->round($this->pickupWeather->temperature) }}&deg;
                    </p>
                    <p class="text-[1.0625rem] font-medium uppercase tracking-[0.06em] opacity-70">
                        Pick-up {{ $this->pickupLabel }}@if ($this->pickupWeather->mentionsRain()) · take a coat @endif
                    </p>
                </div>
            </div>
        @endif
    </div>

    {{-- A column each, in the order the household is listed. --}}
    <div class="grid min-h-0 flex-1 gap-4" style="grid-template-columns: repeat({{ max(1, $members->count()) }}, minmax(0, 1fr));">
        @foreach ($members as $member)
            @php
                $events = $this->eventsByMember->get($member->id, collect());
                $routine = $this->routines->get($member->id);
                $chores = $this->chores->get($member->id, collect());
                $needs = $this->needed->get($member->id, []);
            @endphp

            <section class="flex min-h-0 flex-col rounded-[2rem] bg-white/70 p-5 dark:bg-slate-900/70"
                     wire:key="morning-member-{{ $member->id }}">
                <header class="flex shrink-0 items-center gap-3">
                    <span class="size-5 shrink-0 rounded-full" style="background-color: {{ $member->colour }};" aria-hidden="true"></span>
                    <h2 class="truncate text-[1.75rem] font-semibold">{{ $member->name }}</h2>
                </header>

                <div class="pane-scroll mt-3 min-h-0 flex-1 space-y-5">
                    {{-- Needed today. First, because it is the thing that
                         cannot be done once the door has shut. --}}
                    @if ($needs !== [])
                        <div>
                            <p class="text-[1.0625rem] font-medium uppercase tracking-[0.06em] opacity-70">Needed today</p>
                            <ul class="mt-2 flex flex-wrap gap-2">
                                @foreach ($needs as $item)
                                    <li class="flex items-center gap-2 rounded-full bg-amber-100 px-4 py-2 text-[1.375rem] font-medium text-amber-900 dark:bg-amber-900/40 dark:text-amber-100">
                                        <x-icon name="bag" class="size-6 shrink-0" />
                                        {{ $item }}
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    {{-- The routine, if one is running. Tap to tick. --}}
                    @if ($routine)
                        <div>
                            <p class="flex items-baseline justify-between gap-2 text-[1.0625rem] font-medium uppercase tracking-[0.06em] opacity-70">
                                <span class="truncate">{{ $routine->routine->name }}</span>
                                <span class="shrink-0 tabular-nums">{{ $routine->summary() }}</span>
                            </p>
                            <ul class="mt-2 space-y-1.5">
                                @foreach ($routine->steps as $entry)
                                    <li wire:key="step-{{ $entry['step']->id }}">
                                        <button type="button" wire:click="tickStep({{ $entry['step']->id }})"
                                                class="flex w-full touch-target items-center gap-3 rounded-2xl px-4 text-left text-[1.375rem] {{ $entry['done'] ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-100' : 'bg-slate-100 dark:bg-slate-800' }}">
                                            <x-icon :name="$entry['done'] ? 'ticked' : 'unticked'" class="size-7 shrink-0" />
                                            <span class="min-w-0 flex-1 truncate {{ $entry['done'] ? 'line-through opacity-70' : '' }}">{{ $entry['step']->title }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    {{-- Chores due today. --}}
                    @if ($chores->isNotEmpty())
                        <div>
                            <p class="text-[1.0625rem] font-medium uppercase tracking-[0.06em] opacity-70">Jobs</p>
                            <ul class="mt-2 space-y-1.5">
                                @foreach ($chores as $slot)
                                    <li wire:key="chore-{{ $slot->chore->id }}">
                                        <button type="button" wire:click="tickChore({{ $slot->chore->id }})"
                                                class="flex w-full touch-target items-center gap-3 rounded-2xl px-4 text-left text-[1.375rem] {{ $slot->isDone() ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-100' : 'bg-slate-100 dark:bg-slate-800' }}">
                                            <x-icon :name="$slot->isDone() ? 'ticked' : 'unticked'" class="size-7 shrink-0" />
                                            <span class="min-w-0 flex-1 truncate {{ $slot->isDone() ? 'line-through opacity-70' : '' }}">{{ $slot->chore->title }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    {{-- What is on. --}}
                    <div>
                        <p class="text-[1.0625rem] font-medium uppercase tracking-[0.06em] opacity-70">Today</p>
                        @forelse ($events as $event)
                            <p class="flex items-baseline gap-3 py-1 text-[1.375rem]" wire:key="ev-{{ $event->occurrence_id ?? $event->id }}">
                                <span class="w-16 shrink-0 tabular-nums opacity-70">
                                    {{ $event->all_day ? 'All' : $event->start_at->timezone($tz)->format('H:i') }}
                                </span>
                                <span class="min-w-0 flex-1 truncate font-medium">{{ $event->title }}</span>
                            </p>
                        @empty
                            <p class="py-1 text-[1.375rem] opacity-70">Nothing on.</p>
                        @endforelse
                    </div>
                </div>
            </section>
        @endforeach
    </div>
</div>
