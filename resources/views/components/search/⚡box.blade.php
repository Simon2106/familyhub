<?php

use App\Services\Search\HouseholdSearch;
use App\Services\Search\SearchQuery;
use App\Models\Household;
use App\Services\Search\SearchResult;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * One box that looks everywhere.
 *
 * Searched as you type, with a debounce long enough that a phone keyboard does
 * not fire a query per letter and short enough that it feels immediate.
 */
new class extends Component
{
    public string $q = '';

    /** Where the results can be opened without leaving the page. */
    public bool $canOpen = false;

    /**
     * On the wall, a result switches tab rather than following a link.
     *
     * The display is a kiosk with no address bar: navigating away from it is
     * a one-way trip until somebody restarts Chromium.
     */
    public bool $onWall = false;

    public function mount(bool $canOpen = false, bool $onWall = false): void
    {
        $this->canOpen = $canOpen;
        $this->onWall = $onWall;
    }

    /** @return Collection<string, Collection<int, SearchResult>> */
    #[Computed]
    public function results(): Collection
    {
        if (mb_strlen(trim($this->q)) < 2) {
            return collect();
        }

        return app(HouseholdSearch::class)
            ->search($this->q)
            ->groupBy(fn (SearchResult $r) => $r->group());
    }

    #[Computed]
    public function total(): int
    {
        return $this->results->flatten()->count();
    }

    /** What period, if any, the query narrowed to — worth saying out loud. */
    #[Computed]
    public function period(): ?string
    {
        return SearchQuery::parse($this->q, Household::current()->todayLocal())->period;
    }

    public function clear(): void
    {
        $this->reset('q');

        unset($this->results, $this->total, $this->period);
    }

    /** Open a result in place, where the thing that shows it is on this page. */
    public function open(string $event, int $id): void
    {
        $this->dispatch($event, $id);
        $this->clear();
    }

    /** Send the wall to the tab holding this thing, and open it if we can. */
    public function goToTab(string $tab, ?string $event = null, ?int $id = null): void
    {
        if ($event && $id) {
            $this->dispatch($event, $id);
        }

        $this->dispatch('wall-tab', tab: $tab);
        $this->clear();
    }
}; ?>

<div class="relative">
    <label class="relative block">
        <span class="sr-only">Search</span>
        <svg class="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-slate-400"
             fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="11" cy="11" r="7" />
            <path d="m20 20-3.5-3.5" />
        </svg>

        <input
            wire:model.live.debounce.250ms="q"
            type="search"
            enterkeyhint="search"
            placeholder="Search — try “dentist march”"
            class="touch-target w-full rounded-2xl border border-slate-200 bg-white pr-10 pl-10 dark:border-slate-700 dark:bg-slate-900"
        >

        @if ($q !== '')
            <button type="button" wire:click="clear"
                    class="absolute top-1/2 right-2 grid size-8 -translate-y-1/2 place-items-center rounded-full text-slate-400"
                    aria-label="Clear search">
                <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M6 6l12 12M18 6 6 18" />
                </svg>
            </button>
        @endif
    </label>

    @if (mb_strlen(trim($q)) >= 2)
        {{-- A dropdown everywhere except the wall, where it sits inside the
             dialog instead: an absolutely-positioned panel hanging below the
             input is clipped by the dialog's own scroll box, so the results
             were there and invisible. --}}
        <div class="{{ $onWall
                ? 'mt-2 max-h-[55vh]'
                : 'absolute inset-x-0 top-full z-40 mt-1 max-h-[60vh] shadow-xl ring-1 ring-slate-200 dark:ring-slate-700' }}
                    pane-scroll overflow-y-auto rounded-2xl bg-white dark:bg-slate-900">
            @if ($this->total === 0)
                <p class="px-4 py-6 text-center text-sm text-slate-400">
                    Nothing matches “{{ trim($q) }}”.
                </p>
            @else
                @if ($this->period)
                    <p class="border-b border-slate-100 px-4 py-2 text-xs font-semibold tracking-wide text-slate-400 uppercase dark:border-slate-800">
                        {{ $this->total }} in {{ $this->period }}
                    </p>
                @endif

                @foreach ($this->results as $group => $found)
                    <p class="bg-slate-50 px-4 py-1.5 text-xs font-semibold tracking-wide text-slate-500 uppercase dark:bg-slate-800/60 dark:text-slate-400">
                        {{ $group }}
                    </p>

                    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($found as $result)
                            <li wire:key="hit-{{ $result->type }}-{{ $loop->parent->index }}-{{ $loop->index }}">
                                @php
                                    // In place where the thing that shows it is
                                    // on this page; a tab switch on the wall,
                                    // which must never follow a link; a link
                                    // everywhere else.
                                    $event = $result->opens ? array_key_first($result->opens) : null;
                                    $inPlace = $canOpen && $event;
                                    $tab = $onWall ? $result->wallTab() : null;
                                @endphp

                                @if ($inPlace)
                                    <button type="button" wire:click="open('{{ $event }}', {{ $result->opens[$event] }})"
                                            class="flex w-full touch-target items-start gap-3 px-4 py-2.5 text-left">
                                @elseif ($onWall && $tab)
                                    <button type="button"
                                            wire:click="goToTab('{{ $tab }}'@if ($event), '{{ $event }}', {{ $result->opens[$event] }}@endif)"
                                            class="flex w-full touch-target items-start gap-3 px-4 py-2.5 text-left">
                                @elseif ($onWall)
                                    {{-- Nowhere on the wall to send them: shown,
                                         but not pretending to be a way in. --}}
                                    <span class="flex w-full items-start gap-3 px-4 py-2.5 text-left opacity-60">
                                @else
                                    <a href="{{ $result->url ?? '#' }}" wire:navigate
                                       class="flex w-full touch-target items-start gap-3 px-4 py-2.5 text-left">
                                @endif
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate font-medium">{{ $result->title }}</span>
                                            @if ($result->snippet)
                                                <span class="block truncate text-sm text-slate-500 dark:text-slate-400">{{ $result->snippet }}</span>
                                            @endif
                                        </span>
                                        @if ($result->date)
                                            <span class="shrink-0 text-right text-sm text-slate-400 tabular-nums">
                                                {{ $result->date->isSameYear(now()) ? $result->date->format('D j M') : $result->date->format('j M y') }}
                                            </span>
                                        @endif
                                @if ($inPlace || ($onWall && $tab))
                                    </button>
                                @elseif ($onWall)
                                    </span>
                                @else
                                    </a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endforeach
            @endif
        </div>
    @endif
</div>
