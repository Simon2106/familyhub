<?php

use App\Models\Household;
use App\Models\Recipe;
use App\Services\Recipes\StepSplitter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Cooking from the wall.
 *
 * One step to a screen, because a wall is read from across a kitchen by
 * somebody whose hands are covered in flour. Everything about it is sized for
 * that: two enormous buttons, no scrolling within a step, and a screen that
 * refuses to go to sleep while it is open.
 *
 * The ingredient ticks live here rather than in the database. They are about
 * this evening, not about the recipe, and a chilli that remembered which
 * onions were chopped in March would be worse than one that remembered
 * nothing.
 */
new class extends Component
{
    public ?int $recipeId = null;

    public int $step = 0;

    /** @var list<int> */
    public array $gathered = [];

    /**
     * How many this is being cooked for.
     *
     * Null until somebody changes it, so a recipe written for four stays
     * written for four and nothing is scaled by a rounding error.
     */
    public ?int $servings = null;

    #[On('cook')]
    public function start(int $recipe): void
    {
        $this->recipeId = $recipe;
        $this->step = 0;
        $this->gathered = [];
        $this->servings = null;

        unset($this->recipe, $this->steps, $this->screens, $this->current, $this->isLast);

        // The wall holds its screensaver while this is open: a photograph
        // over step four is something somebody has to wipe away with floury
        // hands.
        $this->dispatch('cooking-started');
    }

    public function close(): void
    {
        $this->reset(['recipeId', 'step', 'gathered', 'servings']);

        unset($this->recipe, $this->steps, $this->screens, $this->current, $this->isLast);

        $this->dispatch('cooking-ended');
    }

    #[Computed]
    public function recipe(): ?Recipe
    {
        return $this->recipeId
            ? Recipe::where('household_id', Household::current()->id)->find($this->recipeId)
            : null;
    }

    /**
     * The steps, derived once where the recipe never had any.
     *
     * Half of what lands in the box is a photograph of a page or an
     * Instagram caption, and cook mode shows one step a screen — so a
     * recipe with no steps is one it cannot open at all. The splitter
     * writes what it works out back to the row, so this happens once.
     *
     * @return list<string>
     */
    #[Computed]
    public function steps(): array
    {
        return $this->recipe ? app(StepSplitter::class)->for($this->recipe) : [];
    }

    /**
     * The screens, in order: the ingredients first, then a step each.
     *
     * The ingredients are a screen of their own rather than a panel beside
     * every step. Gathering happens once, at the start, and repeating the list
     * next to step four is how a wall runs out of room for the step.
     *
     * @return list<array{kind: string, index: int}>
     */
    #[Computed]
    public function screens(): array
    {
        $recipe = $this->recipe;

        if (! $recipe) {
            return [];
        }

        $screens = [];

        if ($recipe->hasIngredients()) {
            $screens[] = ['kind' => 'ingredients', 'index' => 0];
        }

        foreach ($this->steps as $index => $ignored) {
            $screens[] = ['kind' => 'step', 'index' => $index];
        }

        return $screens;
    }

    /* ----------------------------- servings ----------------------------- */

    /** What the recipe was written for, where it says. */
    #[Computed]
    public function baseServings(): ?int
    {
        $written = (int) ($this->recipe?->servings ?? 0);

        return $written > 0 ? $written : null;
    }

    /** How many it is being cooked for now. */
    #[Computed]
    public function cookingFor(): ?int
    {
        return $this->servings ?? $this->baseServings;
    }

    /** Only where there is something to scale and a number to scale it by. */
    #[Computed]
    public function canScale(): bool
    {
        return $this->baseServings !== null && $this->recipe?->hasIngredients();
    }

    public function scaleTo(int $servings): void
    {
        $this->servings = max(1, min(24, $servings));
    }

    /**
     * One ingredient line, scaled to however many are eating.
     *
     * Quantities only: "a pinch" does not double, and a recipe that said
     * "2 x 400g tins" and now says "3.2 tins" has stopped being useful.
     * Rounded to something a kitchen scale can read.
     *
     * @param  array{quantity: float|null, unit: string|null, item: string, note: string|null}  $line
     */
    public function ingredientLine(array $line): string
    {
        $quantity = $line['quantity'];

        if ($quantity !== null && $this->canScale && $this->cookingFor !== $this->baseServings) {
            $quantity = $quantity * $this->cookingFor / $this->baseServings;
        }

        // Joined from the parts that exist rather than concatenated with
        // spaces: an ingredient with no unit was rendering "4  onion".
        return implode(' ', array_filter([
            $quantity === null ? null : $this->tidyNumber($quantity),
            $line['unit'],
            $line['item'],
        ]));
    }

    /** 0.5 rather than 0.50, 2 rather than 2.00. */
    protected function tidyNumber(float $quantity): string
    {
        $rounded = round($quantity, $quantity < 10 ? 2 : 0);

        return rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.') ?: '0';
    }

    #[Computed]
    public function current(): ?array
    {
        return $this->screens[$this->step] ?? null;
    }

    /** The words of a step. */
    public function stepText(int $index): string
    {
        return $this->steps[$index] ?? '';
    }

    public function next(): void
    {
        $this->step = min($this->step + 1, max(0, count($this->screens) - 1));
    }

    public function back(): void
    {
        $this->step = max(0, $this->step - 1);
    }

    public function gather(int $index): void
    {
        $this->gathered = in_array($index, $this->gathered, true)
            ? array_values(array_diff($this->gathered, [$index]))
            : [...$this->gathered, $index];
    }

    /**
     * The minutes a step mentions, for the timer buttons.
     *
     * Read out of the words rather than asked for: nobody who has just read
     * "simmer for 20 minutes" wants to then type 20 with a wooden spoon in
     * their hand.
     *
     * @return list<int>
     */
    public function minutesIn(string $text): array
    {
        preg_match_all('/(\\d{1,3})\\s*(?:-|to|–)?\\s*(\\d{1,3})?\\s*(minutes|minute|mins|min)\\b/i', $text, $matches, PREG_SET_ORDER);

        $minutes = [];

        foreach ($matches as $match) {
            // "20-25 minutes" offers the longer one: a timer that goes off
            // early is a timer somebody has to set again.
            $minutes[] = (int) ($match[2] !== '' ? $match[2] : $match[1]);
        }

        return array_values(array_unique(array_filter($minutes, fn (int $m) => $m > 0 && $m <= 240)));
    }

    #[Computed]
    public function isLast(): bool
    {
        return $this->step >= count($this->screens) - 1;
    }
}; ?>

<div>
    @if ($this->recipe && $this->current)
        @php
            $recipe = $this->recipe;
            $screen = $this->current;
            $total = count($this->screens);
        @endphp

        {{-- Not <x-modal>: this is not a dialog over the wall, it is the wall
             for as long as somebody is cooking. It covers everything, has one
             way out, and keeps the screen awake. --}}
        <div class="fixed inset-0 z-[60] flex flex-col bg-white dark:bg-slate-950"
             x-data="cookMode()"
             role="dialog" aria-modal="true" aria-label="Cooking {{ $recipe->title }}">

            <header class="flex shrink-0 items-center gap-4 px-8 pt-6 pb-2">
                <div class="min-w-0 flex-1">
                    <h2 class="truncate text-2xl font-bold">{{ $recipe->title }}</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        {{ $screen['kind'] === 'ingredients' ? 'What you need' : 'Step '.($screen['index'] + 1).' of '.($total - ($recipe->hasIngredients() ? 1 : 0)) }}
                        <span x-show="awake" x-cloak class="ml-2">· screen staying on</span>
                    </p>
                </div>

                <button type="button" wire:click="close"
                        class="grid touch-target shrink-0 place-items-center rounded-2xl bg-slate-100 px-6 text-lg font-semibold dark:bg-slate-800">
                    I&rsquo;m done
                </button>
            </header>

            {{-- How far through, as a bar rather than a number: it is read at
                 a glance from the other side of a kitchen. --}}
            <div class="mx-8 h-1.5 shrink-0 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                <div class="h-full rounded-full bg-blue-600 transition-all"
                     style="width: {{ $total > 0 ? round(($step + 1) / $total * 100) : 0 }}%"></div>
            </div>

            {{-- Servings, where the recipe said how many it was for. One row
                 rather than a dialog: scaling is decided once, before
                 anybody has started. --}}
            @if ($this->canScale)
                <div class="flex shrink-0 items-center gap-3 px-8 pt-4">
                    <span class="text-[1.0625rem] font-medium uppercase tracking-[0.06em] opacity-70">Cooking for</span>
                    @foreach ([1, 2, 3, 4, 6, 8] as $many)
                        <button type="button" wire:click="scaleTo({{ $many }})"
                                class="touch-target rounded-full px-5 text-[1.375rem] font-medium tabular-nums {{ $this->cookingFor === $many ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-800' }}">
                            {{ $many }}
                        </button>
                    @endforeach
                    @if ($this->cookingFor !== $this->baseServings)
                        <span class="text-[1.0625rem] opacity-70">written for {{ $this->baseServings }}</span>
                    @endif
                </div>
            @endif

            {{-- Swiping moves a step, which is what a hand already reaching
                 for the screen expects. The buttons stay: a wet hand is not
                 a precise one. --}}
            <div class="min-h-0 flex-1 overflow-y-auto px-8 pt-6 pb-10"
                 x-on:touchstart="swipeFrom = $event.changedTouches[0].clientX"
                 x-on:touchend="
                     const dx = $event.changedTouches[0].clientX - (swipeFrom ?? 0);
                     if (Math.abs(dx) > 70) dx < 0 ? $wire.next() : $wire.back();
                 ">
                @if ($screen['kind'] === 'ingredients')
                    <ul class="mx-auto max-w-5xl space-y-2">
                        @foreach ($recipe->ingredientList() as $index => $line)
                            <li wire:key="ing-{{ $index }}">
                                <button type="button" wire:click="gather({{ $index }})"
                                        class="flex w-full items-center gap-4 rounded-2xl px-4 py-3 text-left {{ in_array($index, $gathered, true) ? 'bg-emerald-50 dark:bg-emerald-950/40' : 'bg-slate-50 dark:bg-slate-900' }}">
                                    <span class="grid size-12 shrink-0 place-items-center rounded-xl border-2 {{ in_array($index, $gathered, true) ? 'border-transparent bg-emerald-500 text-white' : 'border-slate-300 dark:border-slate-600' }}">
                                        @if (in_array($index, $gathered, true))
                                            <x-icon name="check" class="size-8" />
                                        @endif
                                    </span>
                                    <span class="min-w-0 flex-1 text-3xl {{ in_array($index, $gathered, true) ? 'text-slate-400 line-through' : '' }}">
                                        {{ $this->ingredientLine($line) }}@if ($line['note'])<span class="text-slate-400">, {{ $line['note'] }}</span>@endif
                                    </span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @else
                    {{-- my-auto rather than justify-center: auto margins collapse when the
                         step is taller than the screen, where centring would clip
                         the first line above the scroll and put it out of reach. --}}
                    <div class="mx-auto flex h-full max-w-5xl flex-col">
                      <div class="my-auto">
                        <p class="text-5xl leading-snug">{{ $this->stepText($screen['index']) }}</p>

                        {{-- A timer for the minutes the step mentions. Found in
                             the words rather than asked for: nobody reading
                             "simmer for 20 minutes" wants to then type 20. --}}
                        <div class="mt-8 flex flex-wrap items-center gap-3"
                             x-data="{ minutes: @js($this->minutesIn($this->stepText($screen['index']))) }">
                            <template x-for="m in minutes" :key="m">
                                <button type="button" x-on:click="startTimer(m, @js($recipe->title))"
                                        class="touch-target rounded-2xl bg-blue-600 px-8 py-4 text-2xl font-semibold text-white">
                                    <span x-text="`Start ${m} min`"></span>
                                </button>
                            </template>

                            <template x-if="! minutes.length">
                                <div class="flex items-center gap-2">
                                    <template x-for="m in [5, 10, 20]" :key="m">
                                        <button type="button" x-on:click="startTimer(m, @js($recipe->title))"
                                                class="touch-target rounded-2xl bg-slate-100 px-6 py-4 text-2xl font-semibold dark:bg-slate-800">
                                            <span x-text="`${m} min`"></span>
                                        </button>
                                    </template>
                                </div>
                            </template>
                        </div>
                      </div>
                    </div>
                @endif
            </div>

            {{-- Big enough for messy hands, and always in the same place. --}}
            <footer class="flex shrink-0 gap-3 px-8 pt-2 pb-8">
                <button type="button" wire:click="back" @disabled($step === 0)
                        class="touch-target flex-1 rounded-2xl bg-slate-100 py-6 text-2xl font-bold disabled:opacity-30 dark:bg-slate-800">
                    Back
                </button>
                <button type="button" wire:click="{{ $this->isLast ? 'close' : 'next' }}"
                        class="touch-target flex-[2] rounded-2xl bg-blue-600 py-6 text-2xl font-bold text-white">
                    {{ $this->isLast ? 'Finished' : 'Next' }}
                </button>
            </footer>
        </div>
    @endif
</div>
