<?php

use App\Models\Household;
use App\Models\Meal;
use App\Models\Recipe;
use App\Services\Meals\ShoppingListGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The weekly meal planner, laid out like the paper one it replaces.
 *
 * One row per day, Monday to Sunday, dinner as the main cell. Free text is
 * first-class: "leftovers", "out" and "Nanny's" are meals, and a plan that
 * insisted on a recipe for each would be a plan nobody filled in.
 */
new class extends Component
{
    /** Weeks from the current one. Kept small and explicit rather than a date. */
    public int $weekOffset = 0;

    /** The cell being edited, as "Y-m-d|slot", or null when the dialog is shut. */
    public ?string $editing = null;

    public string $title = '';

    /** Picker tab: favourites first, because that is what makes a week quick. */
    public string $picking = 'favourites';

    public function household(): Household
    {
        return Household::current();
    }

    #[Computed]
    public function weekStart(): CarbonImmutable
    {
        return $this->household()->weekStart()->addWeeks($this->weekOffset);
    }

    /**
     * @return list<array{date: string, carbon: CarbonImmutable, is_today: bool}>
     */
    #[Computed]
    public function days(): array
    {
        $today = $this->household()->todayLocal()->toDateString();

        return array_map(function (int $offset) use ($today) {
            $day = $this->weekStart->addDays($offset);

            return [
                'date' => $day->toDateString(),
                'carbon' => $day,
                'is_today' => $day->toDateString() === $today,
            ];
        }, range(0, 6));
    }

    /**
     * The slots this household plans.
     *
     * Not named `slots`: Livewire 4 has a slots feature of its own, and a
     * computed property of that name is silently shadowed by an empty
     * collection — the writes then fail their own guard and no-op in silence.
     *
     * @return list<string>
     */
    #[Computed]
    public function plannedSlots(): array
    {
        return $this->household()->mealSlots();
    }

    /**
     * Every planned meal this week, keyed by cell so the grid is one lookup
     * per square rather than one query.
     *
     * @return Collection<string, Meal>
     */
    #[Computed]
    public function meals(): Collection
    {
        return Meal::query()
            ->where('household_id', $this->household()->id)
            ->between($this->weekStart->toDateString(), $this->weekStart->addDays(6)->toDateString())
            ->with('recipe')
            ->get()
            ->keyBy(fn (Meal $meal) => $meal->cell());
    }

    /** @return Collection<int, Recipe> */
    #[Computed]
    public function pickable(): Collection
    {
        return Recipe::query()
            ->where('household_id', $this->household()->id)
            ->ready()
            ->when($this->picking === 'favourites', fn ($q) => $q->where('is_favourite', true))
            ->orderBy('title')
            ->get();
    }

    #[Computed]
    public function favouriteCount(): int
    {
        return Recipe::query()
            ->where('household_id', $this->household()->id)
            ->ready()
            ->where('is_favourite', true)
            ->count();
    }

    /**
     * Ideas saved but not cooked this week, for the shelf under the grid.
     *
     * Anything already planned is left out: the shelf is the pile of things
     * waiting for a day, and an idea that has one is not waiting.
     *
     * @return Collection<int, Recipe>
     */
    #[Computed]
    public function shelf(): Collection
    {
        $planned = $this->meals->pluck('recipe_id')->filter()->all();

        return Recipe::query()
            ->where('household_id', $this->household()->id)
            ->ready()
            ->whereNotIn('id', $planned ?: [0])
            ->orderByDesc('is_favourite')
            ->orderByDesc('id')
            ->limit(12)
            ->get();
    }

    #[On('recipes-changed')]
    public function refreshPickable(): void
    {
        unset($this->pickable, $this->favouriteCount, $this->shelf);
    }

    public function edit(string $cell): void
    {
        [$on] = explode('|', $cell);

        if (! $this->isThisWeek($on)) {
            return;
        }

        $this->editing = $cell;
        $this->title = $this->meals[$cell]->title ?? '';
        $this->picking = $this->favouriteCount > 0 ? 'favourites' : 'all';
    }

    public function save(): void
    {
        $this->validate(['title' => 'required|string|max:120']);

        $this->put(trim($this->title));
    }

    /** Choosing from the box fills the cell in and links the recipe to it. */
    public function choose(int $recipeId): void
    {
        $recipe = Recipe::where('household_id', $this->household()->id)->findOrFail($recipeId);

        $this->put($recipe->title, $recipe->id);
    }

    public function clearCell(): void
    {
        if ($this->editing && ($meal = $this->meals[$this->editing] ?? null)) {
            $meal->delete();
        }

        $this->done();
    }

    /**
     * Drag-and-drop. The meal takes the target cell, replacing whatever was
     * there — the same as picking it up off one square of a paper planner and
     * putting it down on another.
     */
    public function move(int $mealId, string $on, string $slot): void
    {
        $meal = Meal::where('household_id', $this->household()->id)->find($mealId);

        if (! $meal || ! $this->isThisWeek($on) || ! in_array($slot, $this->plannedSlots, true)) {
            return;
        }

        Meal::where('household_id', $this->household()->id)
            ->where('on', $on)
            ->where('slot', $slot)
            ->whereKeyNot($meal->getKey())
            ->delete();

        $meal->update(['on' => $on, 'slot' => $slot]);

        unset($this->meals, $this->shelf);
    }

    /** Dropping an idea from the shelf straight onto a day. */
    public function place(int $recipeId, string $on, string $slot): void
    {
        $recipe = Recipe::where('household_id', $this->household()->id)->find($recipeId);

        if (! $recipe || ! $this->isThisWeek($on) || ! in_array($slot, $this->plannedSlots, true)) {
            return;
        }

        Meal::updateOrCreate(
            ['household_id' => $this->household()->id, 'on' => $on, 'slot' => $slot],
            ['title' => $recipe->title, 'recipe_id' => $recipe->id],
        );

        unset($this->meals, $this->shelf);

        $this->dispatch('meals-changed');
    }

    /**
     * Most weeks look a lot like the last one, which is the whole reason a
     * paper planner gets a photograph taken of it.
     */
    public function copyLastWeek(): void
    {
        $from = $this->weekStart->subWeek();

        $previous = Meal::query()
            ->where('household_id', $this->household()->id)
            ->between($from->toDateString(), $from->addDays(6)->toDateString())
            ->get();

        foreach ($previous as $meal) {
            Meal::updateOrCreate(
                [
                    'household_id' => $this->household()->id,
                    'on' => $meal->on->addWeek()->toDateString(),
                    'slot' => $meal->slot,
                ],
                ['title' => $meal->title, 'recipe_id' => $meal->recipe_id],
            );
        }

        unset($this->meals);

        $this->dispatch('saved', message: $previous->isEmpty()
            ? 'There was nothing planned last week.'
            : 'Copied last week.');
    }

    /**
     * Merge this week's recipes into the shopping list.
     *
     * Deliberately additive and repeatable: it can be run again after a couple
     * more meals are planned without producing a second onion.
     */
    public function generateShoppingList(): void
    {
        $result = app(ShoppingListGenerator::class)->generate(
            $this->household(),
            $this->weekStart->toDateString(),
            $this->weekStart->addDays(6)->toDateString(),
        );

        // The Lists tab may be on screen beside this on the wall.
        $this->dispatch('todos-changed');
        $this->dispatch('saved', message: $result->sentence());
    }

    public function clearWeek(): void
    {
        Meal::query()
            ->where('household_id', $this->household()->id)
            ->between($this->weekStart->toDateString(), $this->weekStart->addDays(6)->toDateString())
            ->delete();

        unset($this->meals);

        $this->dispatch('saved', message: 'Week cleared.');
    }

    public function goToWeek(int $offset): void
    {
        $this->weekOffset = max(-52, min(52, $offset));

        unset($this->weekStart, $this->days, $this->meals);
    }

    protected function put(string $title, ?int $recipeId = null): void
    {
        [$on, $slot] = explode('|', (string) $this->editing);


        if (! $this->isThisWeek($on) || ! in_array($slot, $this->plannedSlots, true)) {
            $this->done();

            return;
        }

        Meal::updateOrCreate(
            ['household_id' => $this->household()->id, 'on' => $on, 'slot' => $slot],
            ['title' => $title, 'recipe_id' => $recipeId],
        );

        $this->done();
    }

    protected function done(): void
    {
        $this->reset(['editing', 'title']);

        unset($this->meals, $this->shelf);

        $this->dispatch('meals-changed');
    }

    /** Guards the cell key, which arrives from the browser. */
    protected function isThisWeek(string $date): bool
    {
        return collect($this->days)->contains('date', $date);
    }
}; ?>

@php
    $slotLabels = ['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner'];
    $extras = array_values(array_diff($this->plannedSlots, ['dinner']));
@endphp

<div class="flex h-full min-h-0 flex-col"
     x-data="mealBoard"
     x-on:pointermove.window="track($event)"
     x-on:pointerup.window="release($event)"
     x-on:pointercancel.window="board.cancel()">

    {{-- ------------------------------ HEADER ---------------------------- --}}
    <div class="flex shrink-0 flex-wrap items-center gap-2 pb-3">
        <h2 class="mr-auto text-lg font-semibold">
            {{ $weekOffset === 0 ? 'This week' : $this->weekStart->format('j M') }}
            <span class="ml-1 text-sm font-normal text-slate-400">
                {{ $this->weekStart->format('j M') }} – {{ $this->weekStart->addDays(6)->format('j M') }}
            </span>
        </h2>

        <div class="flex items-center rounded-xl bg-slate-100 dark:bg-slate-800">
            <button type="button" wire:click="goToWeek({{ $weekOffset - 1 }})"
                    class="grid touch-target place-items-center rounded-xl px-3 text-slate-500" aria-label="Previous week">
                <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6" /></svg>
            </button>
            @if ($weekOffset !== 0)
                <button type="button" wire:click="goToWeek(0)"
                        class="touch-target px-2 text-sm font-semibold text-blue-600 dark:text-blue-400">Today</button>
            @endif
            <button type="button" wire:click="goToWeek({{ $weekOffset + 1 }})"
                    class="grid touch-target place-items-center rounded-xl px-3 text-slate-500" aria-label="Next week">
                <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6" /></svg>
            </button>
        </div>

        <button type="button" wire:click="generateShoppingList"
                class="touch-target rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white">
            <span wire:loading.remove wire:target="generateShoppingList">Shopping list</span>
            <span wire:loading wire:target="generateShoppingList">Adding…</span>
        </button>
        <button type="button" wire:click="copyLastWeek"
                class="touch-target rounded-xl px-3 text-sm font-semibold text-blue-600 dark:text-blue-400">
            Copy last week
        </button>
        <button type="button" wire:click="clearWeek"
                wire:confirm="Clear every meal planned for this week?"
                class="touch-target rounded-xl px-3 text-sm font-semibold text-slate-500">
            Clear week
        </button>
    </div>

    {{-- ------------------------------- GRID ----------------------------- --}}
    <div class="pane-scroll min-h-0 flex-1">
        <div class="space-y-1.5">
            @foreach ($this->days as $day)
                <div class="flex items-stretch gap-2 rounded-2xl p-1 {{ $day['is_today'] ? 'bg-blue-50 dark:bg-blue-950/40' : '' }}"
                     wire:key="day-{{ $day['date'] }}">

                    <div class="flex w-16 shrink-0 flex-col justify-center px-1 sm:w-20">
                        <span class="text-sm font-semibold {{ $day['is_today'] ? 'text-blue-700 dark:text-blue-300' : 'text-slate-500 dark:text-slate-400' }}">
                            {{ $day['carbon']->format('D') }}
                        </span>
                        <span class="text-xs tabular-nums text-slate-400">{{ $day['carbon']->format('j M') }}</span>
                    </div>

                    {{-- Dinner is the meal the household actually plans, so it
                         gets the room. Breakfast and lunch, when switched on,
                         sit beside it at a size that says "optional". --}}
                    @php $dinner = $this->meals[$day['date'].'|dinner'] ?? null; @endphp

                    <button
                        type="button"
                        data-cell="{{ $day['date'] }}|dinner"
                        wire:click="edit('{{ $day['date'] }}|dinner')"
                        @if ($dinner)
                            x-on:pointerdown="lift($event, {{ $dinner->id }}, '{{ $day['date'] }}|dinner', @js($dinner->title))"
                        @endif
                        class="flex min-h-16 min-w-0 flex-1 items-center rounded-xl border px-3 py-2 text-left transition-colors
                               {{ $dinner ? 'border-transparent bg-white shadow-sm dark:bg-slate-900' : 'border-dashed border-slate-300 dark:border-slate-700' }}"
                        :class="drag.over === '{{ $day['date'] }}|dinner' && 'ring-2 ring-blue-500'"
                    >
                        @if ($dinner)
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-semibold">{{ $dinner->title }}</span>
                                @if ($dinner->recipe)
                                    <span class="block truncate text-xs text-slate-400">
                                        {{ $dinner->recipe->summary() ?: 'Recipe attached' }}
                                    </span>
                                @endif
                            </span>
                        @else
                            <span class="text-sm text-slate-400">Add dinner</span>
                        @endif
                    </button>

                    @foreach ($extras as $slot)
                        @php $meal = $this->meals[$day['date'].'|'.$slot] ?? null; @endphp

                        <button
                            type="button"
                            data-cell="{{ $day['date'] }}|{{ $slot }}"
                            wire:click="edit('{{ $day['date'] }}|{{ $slot }}')"
                            @if ($meal)
                                x-on:pointerdown="lift($event, {{ $meal->id }}, '{{ $day['date'] }}|{{ $slot }}', @js($meal->title))"
                            @endif
                            class="hidden w-32 shrink-0 items-center rounded-xl border px-2 py-2 text-left sm:flex lg:w-40
                                   {{ $meal ? 'border-transparent bg-white shadow-sm dark:bg-slate-900' : 'border-dashed border-slate-300 dark:border-slate-700' }}"
                            :class="drag.over === '{{ $day['date'] }}|{{ $slot }}' && 'ring-2 ring-blue-500'"
                        >
                            <span class="min-w-0 flex-1">
                                <span class="block text-[0.65rem] font-semibold tracking-wide text-slate-400 uppercase">{{ $slotLabels[$slot] }}</span>
                                <span class="block truncate text-sm {{ $meal ? 'font-medium' : 'text-slate-400' }}">
                                    {{ $meal->title ?? 'Add' }}
                                </span>
                            </span>
                        </button>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>

    {{-- --------------------------- THE SHELF ---------------------------- --}}
    @if ($this->shelf->isNotEmpty())
        <div class="shrink-0 pt-3">
            <h3 class="px-1 pb-1 text-xs font-semibold tracking-wide text-slate-400 uppercase">Unplanned ideas</h3>
            <div class="pane-scroll flex gap-2 overflow-x-auto pb-1">
                @foreach ($this->shelf as $idea)
                    {{-- Press and hold, then drop it on a day. The same gesture
                         as moving a meal already on the grid. --}}
                    <div
                        wire:key="shelf-{{ $idea->id }}"
                        x-on:pointerdown="lift($event, 'recipe:{{ $idea->id }}', null, @js($idea->title))"
                        class="flex w-40 shrink-0 items-center gap-2 rounded-xl bg-white px-3 py-2 shadow-sm dark:bg-slate-900"
                    >
                        @if ($idea->is_favourite)
                            <svg class="size-4 shrink-0 text-amber-500" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="m12 3 2.9 5.9 6.5.9-4.7 4.6 1.1 6.5-5.8-3-5.8 3 1.1-6.5L2.6 9.8l6.5-.9z" />
                            </svg>
                        @endif
                        <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ $idea->title }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- The meal riding under the finger. --}}
    <template x-if="drag.dragging">
        <div class="drag-ghost rounded-xl bg-white px-3 py-2 text-sm font-semibold shadow-2xl dark:bg-slate-800"
             :style="`left: ${drag.x}px; top: ${drag.y}px`"
             x-text="drag.dragging.title"></div>
    </template>

    {{-- ---------------------------- CELL EDITOR -------------------------- --}}
    @if ($editing)
        @php [$editDate, $editSlot] = explode('|', $editing); @endphp

        <x-modal dismiss="$set('editing', null)"
                 :label="$slotLabels[$editSlot].' on '.\Carbon\CarbonImmutable::parse($editDate)->format('l j F')">
            <div class="flex min-h-0 flex-col p-4">
                <h3 class="text-lg font-semibold">
                    {{ $slotLabels[$editSlot] }} · {{ \Carbon\CarbonImmutable::parse($editDate)->format('l j F') }}
                </h3>

                {{-- Free text first and focused: most meals are typed, not
                     chosen, and the wall's on-screen keyboard is the slow part. --}}
                <form wire:submit="save" class="mt-3 flex gap-2">
                    <input wire:model="title" type="text" autofocus
                           placeholder="Leftovers, out, Nanny's…"
                           class="min-w-0 flex-1 rounded-xl border border-slate-300 px-4 py-3 text-lg dark:border-slate-600 dark:bg-slate-950">
                    <button type="submit" class="touch-target shrink-0 rounded-xl bg-blue-600 px-5 font-semibold text-white">Save</button>
                </form>
                @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

                <div class="mt-4 grid grid-cols-2 gap-1 rounded-xl bg-slate-100 p-1 dark:bg-slate-800">
                    @foreach (['favourites' => 'Favourites', 'all' => 'All recipes'] as $key => $label)
                        <button type="button" wire:click="$set('picking', '{{ $key }}')"
                                class="touch-target rounded-lg text-sm font-semibold {{ $picking === $key ? 'bg-white shadow-sm dark:bg-slate-900' : 'text-slate-500' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                <ul class="mt-2 min-h-0 flex-1 space-y-1 overflow-y-auto">
                    @forelse ($this->pickable as $recipe)
                        <li wire:key="pick-{{ $recipe->id }}">
                            <button type="button" wire:click="choose({{ $recipe->id }})"
                                    class="flex w-full touch-target items-center gap-3 rounded-xl px-2 text-left hover:bg-slate-50 dark:hover:bg-slate-800">
                                @if ($recipe->is_favourite)
                                    <svg class="size-4 shrink-0 text-amber-500" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="m12 3 2.9 5.9 6.5.9-4.7 4.6 1.1 6.5-5.8-3-5.8 3 1.1-6.5L2.6 9.8l6.5-.9z" />
                                    </svg>
                                @endif
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-medium">{{ $recipe->title }}</span>
                                    @if ($recipe->summary())
                                        <span class="block truncate text-xs text-slate-400">{{ $recipe->summary() }}</span>
                                    @endif
                                </span>
                            </button>
                        </li>
                    @empty
                        <li class="px-2 py-3 text-sm text-slate-400">
                            {{ $picking === 'favourites'
                                ? 'No favourites yet — star a few in the recipe box.'
                                : 'Nothing in the recipe box yet.' }}
                        </li>
                    @endforelse
                </ul>

                <div class="mt-3 flex gap-2">
                    <button type="button" wire:click="$set('editing', null)"
                            class="touch-target flex-1 rounded-xl bg-slate-100 font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">Cancel</button>
                    @if ($this->meals[$editing] ?? null)
                        <button type="button" wire:click="clearCell"
                                class="touch-target rounded-xl px-4 font-semibold text-red-600">Clear</button>
                    @endif
                </div>
            </div>
        </x-modal>
    @endif
</div>
