<?php

use App\Models\Household;
use App\Models\Meal;
use App\Models\Member;
use App\Services\Meals\MealFeedback;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * "How was it?" — asked once about a dinner that has been and gone.
 *
 * Deliberately small and deliberately dismissible. A prompt that reappears is
 * one people learn to swipe past without reading, so answering and waving away
 * are the same act: both stamp the meal, and neither is ever asked again.
 */
new class extends Component
{
    public string $note = '';

    public bool $noting = false;

    public function household(): Household
    {
        return Household::current();
    }

    #[Computed]
    public function meal(): ?Meal
    {
        return $this->me ? app(MealFeedback::class)->awaiting($this->household()) : null;
    }

    /** Nobody to attribute a rating to means nothing to ask. */
    #[Computed]
    public function me(): ?Member
    {
        return auth()->user()?->asMember();
    }

    public function rate(int $stars): void
    {
        if (! $this->meal || ! $this->me) {
            return;
        }

        app(MealFeedback::class)->rate($this->meal, $this->me, $stars, $this->noting ? $this->note : null);

        $this->reset('note', 'noting');
        unset($this->meal);

        $this->dispatch('recipes-changed');
        $this->dispatch('saved', message: 'Thanks — noted.');
    }

    public function notNow(): void
    {
        if ($this->meal) {
            app(MealFeedback::class)->dismiss($this->meal);
        }

        $this->reset('note', 'noting');
        unset($this->meal);
    }
}; ?>

<div>
    @if ($this->meal)
        @php $meal = $this->meal; @endphp

        <section class="mt-3 rounded-2xl bg-white p-3 dark:bg-slate-900">
            <div class="flex items-start gap-3">
                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400">
                    <x-icon name="cutlery" class="size-5" />
                </span>

                <div class="min-w-0 flex-1">
                    <p class="font-medium">How was {{ $meal->title }}?</p>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        {{ \Carbon\CarbonImmutable::parse($meal->on)->diffForHumans() }}
                    </p>
                </div>

                <button type="button" wire:click="notNow"
                        class="grid touch-target shrink-0 place-items-center rounded-xl px-2 text-slate-400"
                        aria-label="Not now">
                    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M6 6l12 12M18 6 6 18" />
                    </svg>
                </button>
            </div>

            {{-- One tap is the whole interaction. The note is there for the
                 once in ten times somebody has something to say. --}}
            <div class="mt-2 flex items-center gap-1">
                @for ($star = 1; $star <= 5; $star++)
                    <button type="button" wire:click="rate({{ $star }})"
                            class="grid size-11 place-items-center text-2xl text-amber-400"
                            aria-label="{{ $star }} {{ Str::plural('star', $star) }}">&#9734;</button>
                @endfor

                <button type="button" wire:click="$toggle('noting')"
                        class="ml-auto touch-target rounded-xl px-3 text-sm font-semibold text-slate-500">
                    {{ $noting ? 'Hide note' : 'Add a note' }}
                </button>
            </div>

            @if ($noting)
                <input wire:model="note" type="text" placeholder="“Joey wouldn’t eat the sauce”"
                       class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2 text-base dark:border-slate-600 dark:bg-slate-950">
            @endif
        </section>
    @endif
</div>
