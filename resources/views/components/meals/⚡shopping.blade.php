<?php

use App\Models\Household;
use App\Services\Meals\ShoppingListGenerator;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The shopping list on its own, for use in a supermarket.
 *
 * Nothing else on screen: standing in an aisle with one hand on a trolley is
 * the least forgiving place this app gets used.
 */
new #[Layout('layouts::app')] class extends Component
{
    public function mount(): void
    {
        // So the page is never a dead end before the first generate run.
        ShoppingListGenerator::listFor(Household::current());
    }
}; ?>

<div class="app-shell flex flex-col">
    <header class="flex shrink-0 items-center gap-3 px-4 pt-4 pb-2">
        <a href="{{ route('app') }}" wire:navigate
           class="grid touch-target place-items-center rounded-xl bg-white text-slate-500 dark:bg-slate-900" aria-label="Back">
            <svg class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                <path d="m15 18-6-6 6-6" />
            </svg>
        </a>
        <h1 class="flex-1 text-2xl font-bold">Shopping</h1>
        <a href="{{ route('meals') }}" wire:navigate
           class="touch-target rounded-xl px-3 font-semibold text-blue-600 dark:text-blue-400">Meals</a>
    </header>

    <div class="shrink-0 px-4 pb-2">
        <livewire:search.box />
    </div>

    <div class="min-h-0 flex-1 px-4 pb-8">
        <livewire:display.lists only="shopping" />
    </div>
</div>
