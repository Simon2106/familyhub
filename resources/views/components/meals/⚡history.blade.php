<?php

use App\Models\Household;
use App\Models\Meal;
use App\Services\Meals\MealFeedback;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * What the household actually ate, by week.
 *
 * Read straight off the planner rather than kept as its own record: a week
 * that happened is a week whose days have gone past, and a second table saying
 * so would only ever disagree with the first.
 */
new class extends Component
{
    /** How many weeks back to show before "show more". */
    public int $weeks = 8;

    public function household(): Household
    {
        return Household::current();
    }

    /** @return array<string, \Illuminate\Support\Collection<int, Meal>> */
    #[Computed]
    public function weeksEaten(): array
    {
        return app(MealFeedback::class)->history($this->household(), $this->weeks);
    }

    #[Computed]
    public function total(): int
    {
        return collect($this->weeksEaten)->flatten()->count();
    }

    public function showMore(): void
    {
        $this->weeks += 8;

        unset($this->weeksEaten, $this->total);
    }
}; ?>

<div>
    @forelse ($this->weeksEaten as $weekStart => $meals)
        @php $start = CarbonImmutable::parse($weekStart); @endphp

        <section class="mb-4" wire:key="week-{{ $weekStart }}">
            <h2 class="sticky top-0 z-10 bg-slate-100 py-2 text-sm font-semibold tracking-wide text-slate-500 uppercase dark:bg-slate-950 dark:text-slate-400">
                {{ $start->isSameWeek($this->household()->todayLocal()) ? 'This week' : $start->format('j M') }}
                <span class="font-normal normal-case">– {{ $start->addDays(6)->format('j M Y') }}</span>
            </h2>

            <ul class="space-y-2">
                @foreach ($meals as $meal)
                    @php $idea = $meal->recipe; @endphp

                    <li wire:key="ate-{{ $meal->id }}"
                        class="flex items-center gap-3 rounded-2xl bg-white p-3 dark:bg-slate-900">
                        <span class="w-14 shrink-0 text-sm font-semibold text-slate-500 tabular-nums dark:text-slate-400">
                            {{ CarbonImmutable::parse($meal->on)->format('D j') }}
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{ $meal->title }}</span>
                            <span class="block truncate text-sm text-slate-500 dark:text-slate-400">
                                {{ $meal->slot }}
                                @if ($idea && $idea->timesCooked() > 1)
                                    · had {{ $idea->timesCooked() }} times
                                @endif
                            </span>
                        </span>

                        @if ($idea?->stars())
                            <span class="shrink-0 font-semibold text-amber-500">{{ number_format($idea->stars(), 1) }}&#9733;</span>
                        @elseif ($meal->feedback_at === null)
                            <span class="shrink-0 text-sm text-slate-300 dark:text-slate-600">not rated</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <div class="rounded-2xl border-2 border-dashed border-slate-200 p-8 text-center dark:border-slate-700">
            <p class="font-semibold text-slate-400">Nothing eaten yet.</p>
            <p class="mt-1 text-sm text-slate-400">Days fill in here once they have been and gone.</p>
        </div>
    @endforelse

    @if ($this->total > 0)
        <button type="button" wire:click="showMore"
                class="touch-target w-full rounded-xl text-sm font-semibold text-slate-400">Show more</button>
    @endif
</div>
