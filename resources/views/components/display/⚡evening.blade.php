<?php

use App\Models\BinCollection;
use App\Models\Event;
use App\Models\Household;
use App\Models\Meal;
use App\Models\Member;
use App\Services\Calendar\EventWindow;
use App\Services\Chores\ChoreBoard;
use App\Services\Chores\ChoreSlot;
use App\Services\Display\WallFace;
use App\Services\Routines\RoutineBoard;
use App\Services\Routines\RoutineProgress;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The evening face.
 *
 * From five o'clock a kitchen stops asking what is on today and starts
 * asking what is for tea, what is on tomorrow, and whether the bins go out.
 * Three questions, which is why this face is three panels and not the grid.
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
        return WallFace::GREETINGS[WallFace::EVENING];
    }

    /* ------------------------------- dinner ----------------------------- */

    #[Computed]
    public function dinner(): ?Meal
    {
        return Meal::query()
            ->where('household_id', $this->household()->id)
            ->where('slot', 'dinner')
            ->where('on', $this->today->toDateString())
            ->with('recipe')
            ->first();
    }

    /** Cooking is only offered where there is something to cook from. */
    #[Computed]
    public function canCook(): bool
    {
        return (bool) $this->dinner?->recipe_id;
    }

    public function cook(): void
    {
        if ($recipe = $this->dinner?->recipe_id) {
            $this->dispatch('cook', recipe: $recipe);
        }
    }

    /* ------------------------------ tomorrow ---------------------------- */

    /** @return Collection<int, Event> */
    #[Computed]
    public function tomorrow(): Collection
    {
        $tomorrow = $this->today->addDay();

        return app(EventWindow::class)
            ->between($this->household(), $tomorrow->startOfDay(), $tomorrow->endOfDay())
            ->sortBy([['all_day', 'desc'], ['start_at', 'asc']])
            ->values();
    }

    /* ------------------------------ the bins ---------------------------- */

    /**
     * The bins, but only if they go out tonight.
     *
     * A wall that says "recycling, a week on Tuesday" every evening is a
     * wall nobody reads by Thursday.
     *
     * @return Collection<int, BinCollection>
     */
    #[Computed]
    public function binsTomorrow(): Collection
    {
        return BinCollection::query()
            ->where('household_id', $this->household()->id)
            ->where('on', $this->today->addDay()->toDateString())
            ->orderBy('kind')
            ->get();
    }

    /* ------------------------- routines and chores ----------------------- */

    /** @return Collection<int, RoutineProgress> */
    #[Computed]
    public function routines(): Collection
    {
        return app(RoutineBoard::class)
            ->activeAcross($this->household(), $this->today, $this->household()->nowLocal());
    }

    /**
     * Whatever is still not ticked off today.
     *
     * @return Collection<int, ChoreSlot>
     */
    #[Computed]
    public function chores(): Collection
    {
        return app(ChoreBoard::class)
            ->forDay($this->household(), $this->today)
            ->filter(fn (ChoreSlot $slot) => ! $slot->isDone())
            ->values();
    }

    #[Computed]
    public function members(): Collection
    {
        return $this->household()->members()->get()->keyBy('id');
    }

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

    #[On('meals-changed')]
    public function refreshDinner(): void
    {
        unset($this->dinner, $this->canCook);
    }
}; ?>

@php
    $tz = $this->household()->displayTimezone();
    $members = $this->members;
@endphp

<div class="grid h-full min-h-0 gap-6 xl:grid-cols-3" data-face="evening">
    {{-- Tea. The question actually being asked at five o'clock. --}}
    <x-glass class="flex min-h-0 flex-col p-6">
        <h2 class="t-label shrink-0">Tonight</h2>

        @if ($this->dinner)
            <p class="t-face mt-3">{{ $this->dinner->title }}</p>

            @if ($summary = $this->dinner->recipe?->summary())
                <p class="t-body mt-2 opacity-70">{{ $summary }}</p>
            @endif

            @if ($this->canCook)
                <button type="button" wire:click="cook"
                        class="press touch-target mt-6 w-full rounded-full bg-blue-600 py-5 t-title text-white">
                    Start cooking
                </button>
            @endif
        @else
            <p class="t-title mt-3 font-normal opacity-70">Nothing planned for tonight.</p>
        @endif

        {{-- Bins, only when they go out tonight. --}}
        @if ($this->binsTomorrow->isNotEmpty())
            <div class="mt-auto flex shrink-0 items-center gap-4 rounded-[var(--radius-inner)] px-5 py-4 text-white"
                 style="background-color: {{ $this->binsTomorrow->first()->colour() }};">
                <span class="flex shrink-0 items-center gap-1.5 rounded-full bg-black/25 px-2 py-1.5" aria-hidden="true">
                    @foreach ($this->binsTomorrow as $bin)
                        <span class="size-4 rounded-full" style="background-color: {{ $bin->colour() }};"></span>
                    @endforeach
                </span>
                <span class="t-title min-w-0 flex-1 truncate">
                    {{ $this->binsTomorrow->map(fn ($bin) => $bin->label())->join(' + ') }}
                </span>
                <span class="t-body shrink-0 font-medium opacity-85">out tonight</span>
            </div>
        @endif
    </x-glass>

    {{-- Tomorrow. --}}
    <x-glass class="flex min-h-0 flex-col p-6">
        <h2 class="t-label shrink-0">Tomorrow</h2>

        <div class="pane-scroll mt-3 min-h-0 flex-1">
            @forelse ($this->tomorrow as $event)
                <p class="t-body flex items-baseline gap-4 py-1.5"
                   wire:key="tm-{{ $event->occurrence_id ?? $event->id }}">
                    <span class="w-20 shrink-0 t-num opacity-70">
                        {{ $event->all_day ? 'All day' : $event->start_at->timezone($tz)->format('H:i') }}
                    </span>
                    <span class="flex w-8 shrink-0 items-baseline gap-1">
                        @foreach ($event->members->take(2) as $person)
                            <span class="inline-block size-3 rounded-full" style="background-color: {{ $person->colour ?: '#94a3b8' }};" aria-hidden="true"></span>
                        @endforeach
                    </span>
                    <span class="min-w-0 flex-1 truncate font-medium">{{ $event->title }}</span>
                </p>
            @empty
                <p class="t-body py-1 opacity-70">Nothing on tomorrow.</p>
            @endforelse
        </div>
    </x-glass>

    {{-- Evening routines and whatever is still not done. --}}
    <x-glass class="flex min-h-0 flex-col p-6">
        <h2 class="t-label shrink-0">Before bed</h2>

        <div class="pane-scroll mt-3 min-h-0 flex-1 space-y-5">
            @forelse ($this->routines as $routine)
                <div wire:key="rt-{{ $routine->routine->id }}">
                    <p class="t-body flex items-baseline justify-between gap-2 font-semibold">
                        <span class="flex min-w-0 items-baseline gap-2">
                            <span class="inline-block size-3 shrink-0 rounded-full"
                                  style="background-color: {{ $members->get($routine->routine->member_id)?->colour ?: '#94a3b8' }};" aria-hidden="true"></span>
                            <span class="truncate">{{ $routine->routine->name }}</span>
                        </span>
                        <span class="shrink-0 t-num opacity-70">{{ $routine->summary() }}</span>
                    </p>
                    <ul class="mt-2 space-y-1.5">
                        @foreach ($routine->steps as $entry)
                            <li wire:key="estep-{{ $entry['step']->id }}">
                                <button type="button" wire:click="tickStep({{ $entry['step']->id }})"
                                        class="press flex w-full touch-target items-center gap-3 rounded-[var(--radius-inner)] px-4 text-left t-body {{ $entry['done'] ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-100' : 'bg-slate-100 dark:bg-slate-800' }}">
                                    <x-icon :name="$entry['done'] ? 'ticked' : 'unticked'" class="size-7 shrink-0" />
                                    <span class="min-w-0 flex-1 truncate {{ $entry['done'] ? 'line-through opacity-70' : '' }}">{{ $entry['step']->title }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <p class="t-body opacity-70">No evening routine running.</p>
            @endforelse

            @if ($this->chores->isNotEmpty())
                <div>
                    <p class="t-label">Still to do</p>
                    <ul class="mt-2 space-y-1.5">
                        @foreach ($this->chores as $slot)
                            <li wire:key="echore-{{ $slot->chore->id }}">
                                <button type="button" wire:click="tickChore({{ $slot->chore->id }})"
                                        class="press flex w-full touch-target items-center gap-3 rounded-[var(--radius-inner)] bg-slate-900/5 px-4 text-left t-body dark:bg-white/10">
                                    <x-icon name="unticked" class="size-7 shrink-0" />
                                    <span class="min-w-0 flex-1 truncate">{{ $slot->chore->title }}</span>
                                    @if ($slot->chore->member_id)
                                        <span class="inline-block size-3 shrink-0 rounded-full"
                                              style="background-color: {{ $members->get($slot->chore->member_id)?->colour ?: '#94a3b8' }};" aria-hidden="true"></span>
                                    @endif
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </x-glass>
</div>
