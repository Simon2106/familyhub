<?php

use App\Models\Household;
use App\Models\Recipe;
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

    #[On('cook')]
    public function start(int $recipe): void
    {
        $this->recipeId = $recipe;
        $this->step = 0;
        $this->gathered = [];

        unset($this->recipe);
    }

    public function close(): void
    {
        $this->reset(['recipeId', 'step', 'gathered']);

        unset($this->recipe);
    }

    #[Computed]
    public function recipe(): ?Recipe
    {
        return $this->recipeId
            ? Recipe::where('household_id', Household::current()->id)->find($this->recipeId)
            : null;
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

        foreach ((array) ($recipe->steps ?? []) as $index => $ignored) {
            $screens[] = ['kind' => 'step', 'index' => $index];
        }

        return $screens;
    }

    #[Computed]
    public function current(): ?array
    {
        return $this->screens[$this->step] ?? null;
    }

    /** The words of a step, whichever shape the reader stored them in. */
    public function stepText(int $index): string
    {
        $step = ((array) ($this->recipe?->steps ?? []))[$index] ?? '';

        return is_string($step) ? $step : (string) ($step['text'] ?? '');
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
                    Done
                </button>
            </header>

            {{-- How far through, as a bar rather than a number: it is read at
                 a glance from the other side of a kitchen. --}}
            <div class="mx-8 h-1.5 shrink-0 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                <div class="h-full rounded-full bg-blue-600 transition-all"
                     style="width: {{ $total > 0 ? round(($step + 1) / $total * 100) : 0 }}%"></div>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-8 pt-6 pb-10">
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
                                        {{ trim(($line['quantity'] ? rtrim(rtrim(number_format($line['quantity'], 2, '.', ''), '0'), '.') : '').' '.($line['unit'] ?? '').' '.$line['item']) }}@if ($line['note'])<span class="text-slate-400">, {{ $line['note'] }}</span>@endif
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
                                <button type="button" x-on:click="startTimer(m)"
                                        class="touch-target rounded-2xl bg-blue-600 px-8 py-4 text-2xl font-semibold text-white">
                                    <span x-text="`Start ${m} min`"></span>
                                </button>
                            </template>

                            <template x-if="! minutes.length">
                                <div class="flex items-center gap-2">
                                    <template x-for="m in [5, 10, 20]" :key="m">
                                        <button type="button" x-on:click="startTimer(m)"
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

            {{-- Running timers, above the buttons so a glance finds them. --}}
            <template x-if="timers.length">
                <div class="flex shrink-0 flex-wrap gap-2 px-8 pb-2">
                    <template x-for="timer in timers" :key="timer.id">
                        <button type="button" x-on:click="stopTimer(timer.id)"
                                class="flex items-center gap-3 rounded-2xl px-6 py-3 text-3xl font-bold"
                                :class="timer.left <= 0 ? 'bg-amber-400 text-slate-900' : 'bg-slate-100 dark:bg-slate-800'">
                            <span x-text="timer.left > 0 ? formatted(timer.left) : 'Time!'" class="tabular-nums"></span>
                            <span class="text-base font-normal opacity-70">tap to stop</span>
                        </button>
                    </template>
                </div>
            </template>

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
