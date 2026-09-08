<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::app')] class extends Component
{
    //
}; ?>

<div class="app-shell flex flex-col">
    <header class="flex shrink-0 items-center gap-3 px-4 pt-4 pb-2">
        <a href="{{ route('app') }}" wire:navigate
           class="grid touch-target place-items-center rounded-xl bg-white text-slate-500 dark:bg-slate-900" aria-label="Back">
            <svg class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                <path d="m15 18-6-6 6-6" />
            </svg>
        </a>
        <h1 class="flex-1 text-2xl font-bold">Review</h1>
    </header>

    <div class="shrink-0 px-4 pb-2">
        <livewire:search.box />
    </div>

    <div class="min-h-0 flex-1 px-4 pb-4">
        <div x-data="{ show: false, message: '' }"
             x-on:saved.window="message = $event.detail.message; show = true; setTimeout(() => show = false, 3000)"
             x-show="show" x-cloak x-transition
             class="fixed inset-x-4 top-4 z-[60] rounded-xl bg-slate-900 px-4 py-3 text-white shadow-lg dark:bg-white dark:text-slate-900">
            <span x-text="message"></span>
        </div>

        @if (session('capture-error'))
            <p class="mb-3 rounded-xl bg-red-50 p-3 text-sm text-red-800 dark:bg-red-900/20 dark:text-red-300">
                {{ session('capture-error') }}
            </p>
        @endif

        <div class="mb-3">
            <livewire:capture.intake />
        </div>

        <livewire:capture.review :editable="true" />
    </div>
</div>
