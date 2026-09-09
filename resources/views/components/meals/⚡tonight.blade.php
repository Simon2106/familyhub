<?php

use App\Models\Household;
use App\Models\Meal;
use App\Models\Member;
use App\Models\Recipe;
use App\Services\Meals\MealFeedback;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * What's for tea, and what the children think of it.
 *
 * On the wall, so no PIN and no login: a thumb is not worth guarding, and a
 * keypad between a seven-year-old and an opinion is a keypad that means the
 * opinion never gets given. Each child has their own pair of thumbs rather
 * than a shared one, so "who said that?" is never a question — and tapping the
 * same thumb again takes it back, which is the only undo a wall can offer.
 */
new class extends Component
{
    public function household(): Household
    {
        return Household::current();
    }

    #[Computed]
    public function meal(): ?Meal
    {
        return Meal::query()
            ->where('household_id', $this->household()->id)
            ->where('on', $this->household()->todayLocal()->toDateString())
            ->where('slot', 'dinner')
            ->with('recipe')
            ->first();
    }

    /** @return Collection<int, Member> */
    #[Computed]
    public function children(): Collection
    {
        return $this->household()->members()->children()->orderBy('name')->get();
    }

    /** How each child voted, keyed by member id. */
    #[Computed]
    public function votes(): array
    {
        $idea = $this->meal?->recipe;

        return $idea
            ? $idea->ratings()->whereNotNull('thumbs')->pluck('thumbs', 'member_id')->all()
            : [];
    }

    /**
     * A thumb from a child.
     *
     * A meal typed straight into the planner has no idea behind it, so the
     * first vote promotes it — which is exactly the moment it earns being an
     * idea, because somebody has an opinion about it.
     */
    public function thumb(int $memberId, int $direction): void
    {
        $meal = $this->meal;
        $child = $this->children->firstWhere('id', $memberId);

        if (! $meal || ! $child) {
            return;
        }

        $idea = $meal->recipe ?? app(MealFeedback::class)->promote($meal);

        app(MealFeedback::class)->thumb($idea, $child, $direction);

        unset($this->meal, $this->votes);

        $this->dispatch('recipes-changed');
    }

    #[On('meals-changed')]
    public function refreshTonight(): void
    {
        unset($this->meal, $this->votes);
    }
}; ?>

<div>
    @if ($this->meal && $this->children->isNotEmpty())
        <section class="mb-3 rounded-2xl bg-white p-3 dark:bg-slate-900">
            <div class="flex items-baseline gap-3">
                <h3 class="text-sm font-semibold tracking-wide text-slate-400 uppercase">Tonight</h3>
                <p class="min-w-0 flex-1 truncate text-xl font-semibold">{{ $this->meal->title }}</p>
            </div>

            <ul class="mt-2 flex flex-wrap gap-2">
                @foreach ($this->children as $child)
                    @php $vote = $this->votes[$child->id] ?? null; @endphp

                    <li wire:key="vote-{{ $child->id }}"
                        class="flex items-center gap-2 rounded-xl bg-slate-100 py-1 pr-1 pl-3 dark:bg-slate-800">
                        <span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $child->colour }};"></span>
                        <span class="text-base font-medium">{{ $child->name }}</span>

                        <button type="button" wire:click="thumb({{ $child->id }}, 1)"
                                class="grid size-12 place-items-center rounded-xl text-2xl transition-colors {{ $vote !== null && $vote > 0 ? 'bg-emerald-500/20' : '' }}"
                                aria-label="{{ $child->name }} liked it">👍</button>

                        <button type="button" wire:click="thumb({{ $child->id }}, -1)"
                                class="grid size-12 place-items-center rounded-xl text-2xl transition-colors {{ $vote !== null && $vote < 0 ? 'bg-rose-500/20' : '' }}"
                                aria-label="{{ $child->name }} did not like it">👎</button>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
