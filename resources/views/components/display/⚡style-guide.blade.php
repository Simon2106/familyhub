<?php

use App\Models\Household;
use App\Services\PhotoLibrary;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The wall's style guide, on the wall.
 *
 * Every decision in the design language, on one screen, at the size it will
 * actually be seen — because glass over a photograph is a thing you cannot
 * judge from a swatch, and 17px is a thing you cannot judge from a laptop
 * eighteen inches away.
 *
 * Behind the display token with everything else, and it links nowhere: it is
 * on the kiosk, and the kiosk has no way back.
 */
new #[Layout('layouts::display')] class extends Component
{
    /** Which backdrop the panels are being judged against. */
    public string $backdrop = 'photo';

    public function household(): Household
    {
        return Household::current();
    }

    /** A real photograph from the household's own album, not a stock one. */
    #[Computed]
    public function photo(): ?string
    {
        return app(PhotoLibrary::class)->urls(1)[0] ?? null;
    }

    #[Computed]
    public function type(): array
    {
        return [
            ['Clock', '160px', '10:42', 't-clock'],
            ['Face headline', '48px', 'Good morning', 't-face'],
            ['Card title', '28px', 'Beef chilli', 't-title'],
            ['Body', '22px', 'Simmer for forty minutes.', 't-body'],
            ['Label', '17px', 'Needed today', 't-label'],
        ];
    }
}; ?>

@php
    $photo = $this->photo;
    $grounds = [
        'photo' => 'a photograph',
        'light' => 'light',
        'dark' => 'dark',
    ];
@endphp

<div class="app-shell flex flex-col"
     x-data="{
        backdrop: @js($backdrop),
        tab: 'home',
        init() {
            this.$nextTick(() => this.retint());
            this.$watch('backdrop', () => this.$nextTick(() => this.retint()));
        },
        retint() {
            if (this.backdrop !== 'photo') {
                document.querySelectorAll('.glass').forEach(p => (p.dataset.bright = '0'));
                return;
            }
            window.familyhubGlass?.(this.$refs.ground);
        },
     }"
     :class="backdrop === 'dark' ? 'dark' : ''">

    {{-- The ground being judged against. --}}
    <div class="pointer-events-none fixed inset-0 -z-10 bg-slate-100 dark:bg-slate-950">
        @if ($photo)
            {{-- Sampled once, when it loads and when the ground changes:
                 panels over a bright part of the picture take a heavier tint
                 so their text survives a white shirt. --}}
            <img src="{{ $photo }}" alt="" x-ref="ground" x-show="backdrop === 'photo'"
                 x-on:load="$nextTick(() => retint())"
                 class="h-full w-full object-cover">
        @endif
        <div x-show="backdrop === 'light'" x-cloak class="h-full w-full bg-slate-100"></div>
        <div x-show="backdrop === 'dark'" x-cloak class="h-full w-full bg-slate-950"></div>
    </div>

    <header class="flex shrink-0 items-baseline justify-between gap-6 px-10 pt-6 pb-4">
        <x-glass shape="pill" class="px-7 py-2">
            <h1 class="on-glass t-face text-slate-900 dark:text-white">Wall style</h1>
        </x-glass>

        <x-glass shape="pill" class="flex items-center gap-1 p-1">
            @foreach ($grounds as $key => $label)
                <button type="button" x-on:click="backdrop = '{{ $key }}'"
                        class="press touch-target rounded-full px-5 t-label font-medium"
                        :class="backdrop === '{{ $key }}'
                            ? 'bg-slate-900/85 text-white dark:bg-white/85 dark:text-slate-900'
                            : 'text-slate-900 dark:text-white'">
                    {{ ucfirst($label) }}
                </button>
            @endforeach
        </x-glass>
    </header>

    <div class="pane-scroll min-h-0 flex-1 px-10 pb-10">
        <div class="grid gap-6 xl:grid-cols-3">

            {{-- ------------------------- type ------------------------- --}}
            <x-glass class="p-6 xl:col-span-2">
                <p class="on-glass t-label text-slate-900/70 dark:text-white/70">
                    Type — Inter, tight tracking, tabular figures
                </p>

                <div class="mt-5 space-y-5">
                    @foreach ($this->type as [$name, $px, $sample, $class])
                        <div class="flex items-baseline gap-6">
                            <span class="w-44 shrink-0 t-label text-slate-900/60 dark:text-white/60">
                                {{ $name }}
                            </span>
                            <span class="w-20 shrink-0 t-small t-num text-slate-900/50 dark:text-white/50">
                                {{ $px }}
                            </span>
                            {{-- The clock is shown at a third of its size: at
                                 160px one specimen is the whole panel, and the
                                 point here is the ratio between the five. --}}
                            <span class="on-glass min-w-0 flex-1 truncate text-slate-900 dark:text-white {{ $class }}"
                                  @if ($class === 't-clock') style="font-size: 3.5rem;" @endif>
                                {{ $sample }}
                            </span>
                        </div>
                    @endforeach
                </div>

                <p class="mt-6 t-small text-slate-900/60 dark:text-white/60">
                    Nothing below 17px. Weights: 600 headline, 500 label, 400 body.
                    Figures are tabular everywhere a number can change —
                    <span class="display-digits tabular-nums font-semibold text-slate-900 dark:text-white">11:08</span>
                    does not shuffle sideways when it becomes
                    <span class="display-digits tabular-nums font-semibold text-slate-900 dark:text-white">11:09</span>.
                </p>
            </x-glass>

            {{-- ------------------------ colour ------------------------ --}}
            <x-glass class="p-6">
                <p class="on-glass t-label text-slate-900/70 dark:text-white/70">
                    Colour — one accent per meaning
                </p>

                <ul class="mt-5 space-y-3">
                    @foreach ([
                        ['Member', '#2563eb', 'calendar chips, avatars'],
                        ['Done', '#16a34a', 'ticked, complete'],
                        ['Due soon', '#ea580c', 'today, nearly late'],
                        ['Overdue', '#dc2626', 'past, needs somebody'],
                    ] as [$label, $hex, $use])
                        <li class="flex items-center gap-4">
                            <span class="size-8 shrink-0 rounded-full" style="background-color: {{ $hex }};"></span>
                            <span class="w-28 shrink-0 t-body font-medium text-slate-900 dark:text-white">{{ $label }}</span>
                            <span class="min-w-0 flex-1 truncate t-small text-slate-900/60 dark:text-white/60">{{ $use }}</span>
                        </li>
                    @endforeach
                </ul>

                <p class="mt-5 t-small text-slate-900/60 dark:text-white/60">
                    Everything else is near-neutral and borrows its warmth from whatever is behind it.
                </p>
            </x-glass>

            {{-- ------------------------ buttons ----------------------- --}}
            <x-glass class="p-6">
                <p class="on-glass t-label text-slate-900/70 dark:text-white/70">
                    Buttons and pills
                </p>

                <div class="mt-5 flex flex-wrap items-center gap-3">
                    <button type="button" class="press touch-target rounded-full bg-blue-600 px-8 t-body font-semibold text-white">
                        Start cooking
                    </button>
                    <x-glass as="button" shape="pill" press class="touch-target px-8 t-body font-medium text-slate-900 dark:text-white">
                        Sync now
                    </x-glass>
                    <button type="button" class="press touch-target rounded-full bg-slate-900/10 px-8 t-body font-medium text-slate-900 dark:bg-white/15 dark:text-white">
                        Later
                    </button>
                </div>

                <p class="mt-5 t-small text-slate-900/60 dark:text-white/60">
                    No gradients. Pressed glass compresses to .96 and brightens — hold one down.
                </p>
            </x-glass>

            {{-- ------------------------- chips ------------------------ --}}
            <x-glass class="p-6">
                <p class="on-glass t-label text-slate-900/70 dark:text-white/70">
                    Event chips
                </p>

                <div class="mt-5 space-y-2">
                    @foreach ([
                        ['09:15', 'Dentist', '#2563eb', false],
                        ['15:30', 'Swimming', '#db2777', false],
                        ['18:00', 'Book club', '#16a34a', true],
                    ] as [$when, $what, $colour, $away])
                        <div class="flex items-center gap-3 rounded-[var(--radius-inner)] bg-slate-900/5 px-4 py-2.5 dark:bg-white/10 {{ $away ? 'opacity-60' : '' }}">
                            <span class="size-3 shrink-0 rounded-full" style="background-color: {{ $colour }};"></span>
                            <span class="w-16 shrink-0 t-body t-num text-slate-900/70 dark:text-white/70">{{ $when }}</span>
                            <span class="min-w-0 flex-1 truncate t-body font-medium text-slate-900 dark:text-white">{{ $what }}</span>
                            @if ($away)
                                <span class="shrink-0 t-small text-slate-900/50 dark:text-white/50">away</span>
                            @endif
                        </div>
                    @endforeach
                </div>

                <p class="mt-5 t-small text-slate-900/60 dark:text-white/60">
                    Inner radius is the outer less the inset, so the curves stay concentric.
                </p>
            </x-glass>

            {{-- ------------------------- tiles ------------------------ --}}
            <x-glass class="p-6">
                <p class="on-glass t-label text-slate-900/70 dark:text-white/70">
                    Switch tiles — off is glass, on is warm
                </p>

                <div class="mt-5 grid grid-cols-2 gap-3" x-data="{ on: ['Kitchen'] }">
                    @foreach (['Kitchen', 'Hallway', 'Lamp', 'Porch'] as $name)
                        <button type="button"
                                x-on:click="on = on.includes('{{ $name }}') ? on.filter(n => n !== '{{ $name }}') : [...on, '{{ $name }}']"
                                class="press motion-quick flex items-center gap-3 rounded-[var(--radius-inner)] px-4 py-4 text-left transition-colors"
                                :class="on.includes('{{ $name }}')
                                    ? 'bg-[#fdf0d5] text-slate-900'
                                    : 'bg-slate-900/5 text-slate-900 dark:bg-white/10 dark:text-white'">
                            <x-icon name="light" class="size-8 shrink-0" />
                            <span class="min-w-0 flex-1 truncate t-body font-medium">{{ $name }}</span>
                        </button>
                    @endforeach
                </div>
            </x-glass>

            {{-- ---------------------- a sample card -------------------- --}}
            <x-glass class="p-6 xl:col-span-2">
                <p class="on-glass t-label text-slate-900/70 dark:text-white/70">
                    A day card
                </p>

                <div class="mt-4 flex items-baseline gap-3">
                    <span class="display-digits t-title t-num text-slate-900 dark:text-white">Thu 15</span>
                    <span class="t-small text-slate-900/60 dark:text-white/60">three things on</span>
                </div>

                <div class="mt-4 space-y-2">
                    @foreach ([['08:40', 'School run', '#db2777'], ['13:00', 'Site visit', '#2563eb'], ['19:30', 'Book club', '#16a34a']] as [$when, $what, $colour])
                        <div class="flex items-center gap-3 rounded-[var(--radius-inner)] bg-slate-900/5 px-4 py-2.5 dark:bg-white/10">
                            <span class="size-3 shrink-0 rounded-full" style="background-color: {{ $colour }};"></span>
                            <span class="w-16 shrink-0 t-body t-num text-slate-900/70 dark:text-white/70">{{ $when }}</span>
                            <span class="min-w-0 flex-1 truncate t-body font-medium text-slate-900 dark:text-white">{{ $what }}</span>
                        </div>
                    @endforeach
                </div>
            </x-glass>

            {{-- -------------------- the morphing tab bar --------------- --}}
            <x-glass class="p-6 xl:col-span-3">
                <p class="on-glass t-label text-slate-900/70 dark:text-white/70">
                    The tab bar — one blob that travels, never a fade
                </p>

                <div class="mt-5 flex justify-center">
                    <x-glass shape="pill" class="p-1.5">
                        <x-display.tab-bar :tabs="[
                            ['home', 'Home', 'house'],
                            ['review', 'Review', 'label'],
                            ['meals', 'Meals', 'cutlery'],
                            ['lists', 'Lists', 'check'],
                            ['photos', 'Photos', 'photo'],
                            ['timers', 'Timers', 'timer'],
                        ]" />
                    </x-glass>
                </div>

                <p class="mt-5 text-center t-small text-slate-900/60 dark:text-white/60">
                    360ms, cubic-bezier(.2,.8,.2,1). Tap along the row and watch the blob stretch.
                </p>
            </x-glass>
        </div>
    </div>
</div>
