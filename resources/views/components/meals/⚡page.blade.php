<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Meals as a section rather than a page.
 *
 * Three tabs, because the family asks three different questions of the same
 * data: what are we having, what could we have, and what have we been having.
 * The planner keeps the paper-sheet layout it replaced — that is the bit that
 * works, and it is not the bit that needed promoting.
 */
new #[Layout('layouts::app')] class extends Component
{
    /** week | ideas | history — in the URL so a refresh stays put. */
    #[Url(as: 'tab', except: 'week')]
    public string $tab = 'week';

    public const TABS = ['week' => 'This week', 'ideas' => 'Ideas', 'history' => 'History'];

    public function mount(): void
    {
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'week';
        }
    }
}; ?>

<div class="app-shell flex flex-col">
    <header class="shrink-0 px-4 pt-4 pb-2">
        <div class="flex items-center gap-3">
            <a href="{{ route('app') }}" wire:navigate
               class="grid touch-target place-items-center rounded-xl bg-white text-slate-500 dark:bg-slate-900" aria-label="Back">
                <svg class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="m15 18-6-6 6-6" />
                </svg>
            </a>
            <h1 class="flex-1 text-2xl font-bold">Meals</h1>
            <a href="{{ route('shopping') }}" wire:navigate
               class="touch-target rounded-xl px-3 font-semibold text-blue-600 dark:text-blue-400">Shopping</a>
        </div>

        {{-- Segmented rather than a row of links: three tabs is a control, not
             navigation, and the page underneath does not reload. --}}
        <div class="mt-3 grid grid-cols-3 gap-1 rounded-xl bg-slate-200/70 p-1 dark:bg-slate-800">
            @foreach (self::TABS as $key => $label)
                <button type="button" wire:click="$set('tab', '{{ $key }}')"
                        class="touch-target rounded-lg text-sm font-semibold {{ $tab === $key ? 'bg-white shadow-sm dark:bg-slate-900' : 'text-slate-500 dark:text-slate-400' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </header>

    <div class="shrink-0 px-4 pb-2">
        <livewire:search.box />
    </div>

    <div x-data="{ show: false, message: '' }"
         x-on:saved.window="message = $event.detail.message; show = true; setTimeout(() => show = false, 2500)"
         x-show="show" x-cloak x-transition
         class="fixed inset-x-4 top-4 z-50 rounded-xl bg-slate-900 px-4 py-3 text-white shadow-lg dark:bg-white dark:text-slate-900">
        <span x-text="message"></span>
    </div>

    <div class="pane-scroll min-h-0 flex-1 px-4 pb-8">
        @if ($tab === 'week')
            <livewire:meals.plan />
        @elseif ($tab === 'ideas')
            <livewire:meals.ideas />
        @else
            <livewire:meals.history />
        @endif
    </div>
</div>
