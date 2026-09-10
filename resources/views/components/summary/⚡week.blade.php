<?php

use App\Models\Household;
use App\Services\Summary\SummaryWeek;
use App\Services\Summary\WeeklySummary;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The week, on one screen.
 *
 * Everything here is worked out on the way past — nothing about a summary is
 * stored, which is what lets somebody open last week's on a Wednesday and get
 * the same answer they would have got on Sunday.
 *
 * One screen is the whole brief, so each section gets a fixed share of it and
 * nothing scrolls away into a report.
 */
new #[Layout('layouts::app')] class extends Component
{
    /** Weeks back from the one just finished. In the URL so a link keeps it. */
    #[Url(as: 'back', except: 0)]
    public int $weeksBack = 0;

    public function household(): Household
    {
        return Household::current();
    }

    #[Computed]
    public function week(): SummaryWeek
    {
        $summary = app(WeeklySummary::class);

        return $summary->for(
            $this->household(),
            $summary->weekOf($this->household())->subWeeks(max(0, min(52, $this->weeksBack))),
        );
    }

    public function shift(int $by): void
    {
        $this->weeksBack = max(0, min(52, $this->weeksBack + $by));

        unset($this->week);
    }
}; ?>

<div class="app-shell flex flex-col">
    <header class="flex shrink-0 items-center gap-3 px-4 pt-4 pb-2">
        <a href="{{ route('app') }}" wire:navigate
           class="grid touch-target place-items-center rounded-xl bg-white text-slate-500 dark:bg-slate-900" aria-label="Back">
            <x-icon name="chevron-left" class="size-6" />
        </a>
        <div class="min-w-0 flex-1">
            <h1 class="truncate text-2xl font-bold">The week</h1>
            <p class="truncate text-sm text-slate-500 dark:text-slate-400">{{ $this->week->heading() }}</p>
        </div>

        <button type="button" wire:click="shift(1)"
                class="grid touch-target place-items-center rounded-xl bg-white text-slate-500 dark:bg-slate-900"
                aria-label="The week before">
            <x-icon name="chevron-left" class="size-6" />
        </button>
        <button type="button" wire:click="shift(-1)" @disabled($weeksBack === 0)
                class="grid touch-target place-items-center rounded-xl bg-white text-slate-500 disabled:opacity-30 dark:bg-slate-900"
                aria-label="The week after">
            <x-icon name="chevron-right" class="size-6" />
        </button>
    </header>

    <div class="pane-scroll min-h-0 flex-1 space-y-3 px-4 pb-6">
        {{-- The headline, so the whole week reads in one glance before any
             of the detail underneath it does. --}}
        <div class="rounded-2xl bg-white p-4 dark:bg-slate-900">
            <p class="text-lg font-semibold">{{ $this->week->line() }}</p>
        </div>

        {{-- ------------------------------ children --------------------- --}}
        @if ($this->week->children->isNotEmpty())
            <section class="rounded-2xl bg-white p-4 dark:bg-slate-900">
                <h2 class="text-sm font-semibold tracking-wide text-slate-400 uppercase">Jobs and stars</h2>

                <ul class="mt-2 space-y-2">
                    @foreach ($this->week->children as $child)
                        <li class="flex items-center gap-3" wire:key="child-{{ $child->member->id }}">
                            <span class="size-3 shrink-0 rounded-full"
                                  style="background: {{ $child->member->colour ?: '#94a3b8' }}" aria-hidden="true"></span>

                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-semibold">{{ $child->member->name }}</span>
                                <span class="block truncate text-sm {{ $child->didNothing() ? 'text-slate-400' : 'text-slate-500 dark:text-slate-400' }}">
                                    {{ $child->sentence() }}
                                    @if ($child->choresWaiting > 0)
                                        · {{ $child->choresWaiting }} waiting to be signed off
                                    @endif
                                </span>
                            </span>

                            {{-- The balance, which is the number they actually
                                 ask about, kept in the same place every week. --}}
                            <span class="shrink-0 text-right">
                                <span class="block text-lg font-bold tabular-nums">{{ $child->balance }}</span>
                                <span class="block text-xs text-slate-400">stars</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- ------------------------------- meals ------------------------ --}}
        <section class="rounded-2xl bg-white p-4 dark:bg-slate-900">
            <h2 class="text-sm font-semibold tracking-wide text-slate-400 uppercase">What we ate</h2>

            @if ($this->week->meals->isEmpty())
                <p class="mt-2 text-sm text-slate-400">Nothing was written down for dinner this week.</p>
            @else
                <ul class="mt-2 space-y-1">
                    @foreach ($this->week->meals as $meal)
                        <li class="flex items-baseline gap-3 text-sm" wire:key="meal-{{ $meal->on->toDateString() }}">
                            <span class="w-10 shrink-0 text-slate-400">{{ $meal->on->format('D') }}</span>
                            <span class="min-w-0 flex-1 truncate font-medium">{{ $meal->title }}</span>
                            @if ($meal->verdict())
                                <span class="shrink-0 text-slate-500 dark:text-slate-400">{{ $meal->verdict() }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- ------------------------------- ahead ------------------------ --}}
        <section class="rounded-2xl bg-white p-4 dark:bg-slate-900">
            <h2 class="text-sm font-semibold tracking-wide text-slate-400 uppercase">
                Coming up — {{ $this->week->ahead->weekStart->format('j M') }}
            </h2>

            @if ($this->week->ahead->isQuiet())
                <p class="mt-2 text-sm text-slate-400">Nothing in the calendar. A quiet one.</p>
            @else
                <ul class="mt-2 space-y-1">
                    @foreach ($this->week->ahead->highlights as $item)
                        <li class="flex items-baseline gap-3 text-sm" wire:key="ahead-{{ $loop->index }}">
                            <span class="w-[4.5rem] shrink-0 whitespace-nowrap text-slate-400 tabular-nums">
                                {{ $item['when']->format('D') }}{{ $item['all_day'] ? '' : ' '.$item['when']->format('H:i') }}
                            </span>
                            <span class="min-w-0 flex-1 truncate font-medium">{{ $item['title'] }}</span>
                        </li>
                    @endforeach
                </ul>

                @if ($this->week->ahead->eventCount > count($this->week->ahead->highlights))
                    <p class="mt-1 text-xs text-slate-400">
                        and {{ $this->week->ahead->eventCount - count($this->week->ahead->highlights) }} more in the calendar
                    </p>
                @endif
            @endif

            <p class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-slate-500 dark:text-slate-400">
                <span>{{ $this->week->ahead->mealSentence() }}</span>

                @foreach ($this->week->ahead->countdowns as $countdown)
                    <span wire:key="cd-{{ $countdown->key }}">· {{ $countdown->sentence() }}</span>
                @endforeach
            </p>

            @if ($this->week->ahead->emptyNights > 0)
                <a href="{{ route('meals') }}" wire:navigate
                   class="mt-3 inline-grid touch-target place-items-center rounded-xl bg-blue-600 px-4 font-semibold text-white">
                    Plan the week
                </a>
            @endif
        </section>
    </div>
</div>
