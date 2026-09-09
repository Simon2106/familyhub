<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The switches, on a phone.
 *
 * The same panel the wall shows: a group is a group wherever you are standing,
 * and two implementations of "off in five" would eventually disagree about
 * what five means.
 */
new #[Layout('layouts::app')] class extends Component {}; ?>

<div class="app-shell flex flex-col">
    <header class="flex shrink-0 items-center gap-3 px-4 pt-4 pb-2">
        <a href="{{ route('app') }}" wire:navigate
           class="grid touch-target place-items-center rounded-xl bg-white text-slate-500 dark:bg-slate-900" aria-label="Back">
            <svg class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                <path d="m15 18-6-6 6-6" />
            </svg>
        </a>
        <h1 class="flex-1 text-2xl font-bold">Switches</h1>
        <a href="{{ route('admin.home') }}" wire:navigate
           class="touch-target rounded-xl px-3 font-semibold text-blue-600 dark:text-blue-400">Set up</a>
    </header>

    <div x-data="{ show: false, message: '' }"
         x-on:saved.window="message = $event.detail.message; show = true; setTimeout(() => show = false, 2500)"
         x-show="show" x-cloak x-transition
         class="fixed inset-x-4 top-4 z-50 rounded-xl bg-slate-900 px-4 py-3 text-white shadow-lg dark:bg-white dark:text-slate-900">
        <span x-text="message"></span>
    </div>

    <div class="pane-scroll min-h-0 flex-1 px-4 pb-8">
        <livewire:home.panel />
    </div>
</div>
