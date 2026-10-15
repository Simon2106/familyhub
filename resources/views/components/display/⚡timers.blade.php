<?php

use App\Models\Household;
use App\Models\Timer;
use App\Services\Timers\TimerBoard;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The timer strip, and the dialog for setting one by hand.
 *
 * Pinned across the top of every tab and over the screensaver, because the
 * one thing a kitchen timer must never do is be somewhere else when it goes
 * off.
 *
 * Nothing here counts down. The server hands over end times and the browser
 * does the ticking against its own clock, resyncing every half minute — so a
 * Chromium that restarts comes back to a timer with the right amount left on
 * it, and twenty tabs all agree.
 */
new class extends Component
{
    /** Whether the "new timer" sheet is open. */
    public bool $adding = false;

    public string $newName = '';

    public int $newMinutes = 10;

    protected function board(): TimerBoard
    {
        return app(TimerBoard::class);
    }

    protected function household(): Household
    {
        return Household::current();
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function timers(): array
    {
        return $this->board()->forScreen($this->household());
    }

    #[Computed]
    public function chimes(): bool
    {
        return $this->household()->timerChime();
    }

    /** Minutes offered as one tap each, which is how a kitchen timer is set. */
    #[Computed]
    public function presets(): array
    {
        return [1, 3, 5, 10, 15, 20, 30, 45, 60];
    }

    public function openAdd(): void
    {
        $this->adding = true;
        $this->newName = '';
        $this->newMinutes = 10;
    }

    public function closeAdd(): void
    {
        $this->adding = false;
    }

    /** Set one from the sheet. */
    public function add(): void
    {
        $this->validate([
            'newName' => 'nullable|string|max:60',
            'newMinutes' => 'required|integer|min:1|max:1440',
        ]);

        $this->board()->start(
            $this->household(),
            $this->newMinutes * 60,
            $this->newName,
            'manual',
        );

        $this->adding = false;

        $this->changed();
    }

    /** One tap: a named-nothing timer for that many minutes. */
    public function quick(int $minutes): void
    {
        $this->board()->start($this->household(), max(1, min($minutes, 1440)) * 60, null, 'manual');

        $this->changed();
    }

    /**
     * From cook mode, which knows the step it came from.
     */
    #[On('start-timer')]
    public function startFor(int $minutes, ?string $name = null): void
    {
        $this->board()->start($this->household(), max(1, min($minutes, 1440)) * 60, $name, 'cook');

        $this->changed();
    }

    /** Something elsewhere made one — the voice path, most likely. */
    #[On('timers-changed')]
    public function refresh(): void
    {
        unset($this->timers);
    }

    public function pause(int $id): void
    {
        $timer = $this->find($id);

        $timer?->isPaused() ? $timer->resume() : $timer?->pause();

        $this->changed();
    }

    public function dismiss(int $id): void
    {
        $this->find($id)?->dismiss();

        $this->changed();
    }

    /**
     * Note that the ones that have gone off have gone off.
     *
     * Called by the browser the moment it notices. Recording it server-side
     * is what stops the wall and a phone both chiming for the same pasta.
     */
    public function rang(): void
    {
        $this->board()->ring($this->household());

        unset($this->timers);
    }

    protected function find(int $id): ?Timer
    {
        return Timer::query()->forHousehold($this->household())->find($id);
    }

    protected function changed(): void
    {
        unset($this->timers);

        $this->dispatch('timers-changed')->to('display.wall');
    }
}; ?>

@php
    // Keyed on what actually changes about a timer rather than on how much is
    // left of it: the remaining seconds move every render, and a key that
    // moved with them would throw the element away four times a minute for
    // nothing. The browser does the counting; this only has to carry news.
    $fingerprint = md5(json_encode(array_map(
        fn (array $timer) => [$timer['id'], $timer['endsAt'], $timer['paused'], $timer['rang']],
        $this->timers,
    )));
@endphp

<div
    x-data="timerStrip(@js($this->timers), @js($this->chimes))"
    class="contents"
>
    {{-- How a fresh list reaches Alpine. Livewire replaces this element when
         the fingerprint changes, which runs x-init again; nothing else about
         a re-render would tell the strip anything. --}}
    <div wire:key="timers-{{ $fingerprint }}" x-init="adopt(@js($this->timers))" hidden></div>

    {{-- The strip.

         Floating rather than in the flow, and above everything: cook mode is
         a full-screen layer and the screensaver is another, and a timer
         hidden behind either is a timer that rings in a room nobody is
         looking at. Centre-top is the one part of the header that is empty —
         the clock is left, the weather right.

         Nothing at all when nothing is running: a kitchen wall with a
         permanent empty bar across it has lost sixty pixels for ever. --}}
    <template x-if="timers.length">
        <div class="pointer-events-none fixed inset-x-0 top-0 z-[80] flex flex-wrap items-center justify-center gap-3 px-10 pt-3"
             data-timer-strip>
            <template x-for="timer in timers" :key="timer.id">
                <div class="pointer-events-auto flex items-center gap-3 rounded-full px-5 py-2 text-white shadow-lg backdrop-blur"
                     :class="timer.finished ? 'bg-amber-500' : 'bg-black/55'"
                     data-timer-pill>
                    <span class="max-w-[14rem] truncate text-[1.0625rem] font-medium opacity-80"
                          x-text="timer.name"></span>

                    <span class="display-digits text-2xl font-semibold tabular-nums"
                          x-text="timer.finished ? 'Time' : clock(timer)"></span>

                    <button type="button"
                            x-show="! timer.finished"
                            x-on:click="$wire.pause(timer.id)"
                            class="grid size-11 shrink-0 place-items-center rounded-full bg-white/15"
                            :aria-label="timer.paused ? 'Resume ' + timer.name : 'Pause ' + timer.name">
                        <x-icon name="pause" class="size-5" x-show="! timer.paused" />
                        <x-icon name="play" class="size-5" x-show="timer.paused" x-cloak />
                    </button>

                    <button type="button" x-on:click="$wire.dismiss(timer.id)"
                            class="grid size-11 shrink-0 place-items-center rounded-full bg-white/15"
                            :aria-label="'Dismiss ' + timer.name">
                        <x-icon name="close" class="size-5" />
                    </button>
                </div>
            </template>
        </div>
    </template>

    {{-- The banner. Full width, impossible to miss from the other side of a
         kitchen, and gone on its own after two minutes — a timer nobody is
         in the room for should not still be shouting at bedtime. --}}
    <template x-if="banner">
        <div class="fixed inset-x-0 top-0 z-[90] flex items-center gap-6 bg-amber-400 px-10 py-6 text-slate-900"
             data-timer-banner role="status">
            <x-icon name="bell" class="size-12 shrink-0" />
            <p class="min-w-0 flex-1 truncate text-5xl font-semibold" x-text="banner.name"></p>
            <p class="shrink-0 text-3xl font-medium opacity-70">finished</p>
            <button type="button" x-on:click="$wire.dismiss(banner.id)"
                    class="shrink-0 rounded-full bg-slate-900 px-10 py-4 text-2xl font-semibold text-white">
                Stop
            </button>
        </div>
    </template>

    {{-- Setting one by hand. --}}
    @if ($adding)
        <x-modal close-on="$wire.closeAdd()" label="New timer" width="max-w-2xl">
            <div class="p-6">
                <h2 class="text-[1.75rem] font-semibold">New timer</h2>

                <label class="mt-4 block">
                    <span class="text-[1.0625rem] font-medium uppercase tracking-[0.06em] opacity-70">What for</span>
                    <input wire:model="newName" type="text" placeholder="Pasta"
                           class="touch-target mt-1 w-full rounded-2xl border border-slate-300 px-4 text-[1.375rem] dark:border-slate-700 dark:bg-slate-950">
                </label>

                <div class="mt-5">
                    <span class="text-[1.0625rem] font-medium uppercase tracking-[0.06em] opacity-70">How long</span>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($this->presets as $minutes)
                            <button type="button" wire:click="$set('newMinutes', {{ $minutes }})"
                                    class="touch-target rounded-full px-6 text-[1.375rem] font-medium tabular-nums {{ $newMinutes === $minutes ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-800' }}">
                                {{ $minutes }} min
                            </button>
                        @endforeach
                    </div>
                    @error('newMinutes') <p class="mt-1 text-[1.0625rem] text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="mt-6 flex gap-3">
                    <button type="button" wire:click="closeAdd"
                            class="touch-target flex-1 rounded-2xl bg-slate-100 py-4 text-[1.375rem] font-semibold dark:bg-slate-800">
                        Cancel
                    </button>
                    <button type="button" wire:click="add"
                            class="touch-target flex-[2] rounded-2xl bg-blue-600 py-4 text-[1.375rem] font-semibold text-white">
                        Start
                    </button>
                </div>
            </div>
        </x-modal>
    @endif
</div>
