<?php

use App\Models\Event;
use App\Models\Household;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Phone view. Same data as the wall, laid out for one thumb.
 */
new #[Layout('layouts::app')] class extends Component
{
    public const HORIZON = 14;

    /** Filter to one member; kept in the URL so it survives a refresh. */
    #[Url(as: 'member', except: '')]
    public string $memberFilter = '';

    /** Opened straight from a search result or a link. */
    public function mount(): void
    {
        if ($id = request()->integer('event')) {
            $this->dispatch('edit-event', $id);
        }
    }

    public function household(): Household
    {
        return Household::current();
    }

    #[Computed]
    public function members(): Collection
    {
        return $this->household()->members()->get();
    }

    /** Re-read the agenda after the editor writes through to iCloud. */
    #[On('events-changed')]
    public function refreshAgenda(): void
    {
        unset($this->eventsByDay);
    }

    /** @return Collection<string, Collection<int, Event>> keyed by date */
    #[Computed]
    public function eventsByDay(): Collection
    {
        // Household midnights, not the server's — see Household::todayLocal().
        $from = $this->household()->todayLocal();
        $to = $from->addDays(self::HORIZON);

        $events = Event::query()
            ->notCancelled()
            ->overlapping($from, $to)
            ->whereHas('calendar', function ($q) {
                $q->where('is_visible', true);

                if ($this->memberFilter !== '') {
                    $q->where('member_id', $this->memberFilter);
                }
            })
            ->with('calendar.member')
            ->orderBy('start_at')
            ->get();

        return collect(range(0, self::HORIZON - 1))
            ->mapWithKeys(function (int $i) use ($from, $events) {
                $day = $from->addDays($i);
                $dayEnd = $day->endOfDay();

                return [$day->toDateString() => $events->filter(
                    fn (Event $e) => $e->start_at < $dayEnd && $e->end_at > $day
                )->values()];
            })
            // Days with nothing on are dead weight on a phone.
            ->reject(fn (Collection $events) => $events->isEmpty());
    }
}; ?>

@php $tz = $this->household()->displayTimezone(); @endphp

<div class="app-shell flex flex-col">
    <header class="shrink-0 px-4 pt-4 pb-2">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold">{{ $this->household()->name }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ $this->household()->nowLocal()->format('l j F') }}</p>
            </div>

            <a href="{{ route('admin') }}" wire:navigate
               class="grid touch-target place-items-center rounded-xl bg-white text-slate-500 dark:bg-slate-900"
               aria-label="Settings">
                <svg class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.3 4.3a1 1 0 0 1 1-.8h1.4a1 1 0 0 1 1 .8l.2 1.2a7 7 0 0 1 1.5.9l1.2-.5a1 1 0 0 1 1.2.4l.7 1.2a1 1 0 0 1-.2 1.3l-1 .8a7 7 0 0 1 0 1.7l1 .8a1 1 0 0 1 .2 1.3l-.7 1.2a1 1 0 0 1-1.2.4l-1.2-.5a7 7 0 0 1-1.5.9l-.2 1.2a1 1 0 0 1-1 .8h-1.4a1 1 0 0 1-1-.8l-.2-1.2a7 7 0 0 1-1.5-.9l-1.2.5a1 1 0 0 1-1.2-.4l-.7-1.2a1 1 0 0 1 .2-1.3l1-.8a7 7 0 0 1 0-1.7l-1-.8a1 1 0 0 1-.2-1.3l.7-1.2a1 1 0 0 1 1.2-.4l1.2.5a7 7 0 0 1 1.5-.9z"/>
                    <circle cx="12" cy="12" r="2.5"/>
                </svg>
            </a>
        </div>

        {{-- Member filter --}}
        <div class="pane-scroll -mx-4 mt-3 flex gap-2 overflow-x-auto px-4 pb-1">
            <button type="button" wire:click="$set('memberFilter', '')"
                    class="touch-target shrink-0 rounded-full px-4 text-sm font-semibold {{ $this->memberFilter === '' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'bg-white text-slate-600 dark:bg-slate-900 dark:text-slate-300' }}">
                Everyone
            </button>
            @foreach ($this->members as $member)
                <button type="button" wire:click="$set('memberFilter', '{{ $member->id }}')"
                        class="flex touch-target shrink-0 items-center gap-2 rounded-full px-4 text-sm font-semibold {{ (string) $this->memberFilter === (string) $member->id ? 'text-white' : 'bg-white text-slate-600 dark:bg-slate-900 dark:text-slate-300' }}"
                        @style(["background-color: {$member->colour}" => (string) $this->memberFilter === (string) $member->id])>
                    <span class="size-2.5 rounded-full" style="background-color: {{ (string) $this->memberFilter === (string) $member->id ? '#fff' : $member->colour }};"></span>
                    {{ $member->name }}
                </button>
            @endforeach
        </div>
    </header>

    {{-- One box that looks everywhere, pinned under the header rather than
         scrolling away with the page. --}}
    <div class="shrink-0 px-4 pb-2">
        <livewire:search.box :can-open="true" />
    </div>

    <div x-data="{ show: false, message: '' }"
         x-on:saved.window="message = $event.detail.message; show = true; setTimeout(() => show = false, 2500)"
         x-show="show" x-cloak x-transition
         class="fixed inset-x-4 top-4 z-[60] rounded-xl bg-slate-900 px-4 py-3 text-white shadow-lg dark:bg-white dark:text-slate-900">
        <span x-text="message"></span>
    </div>

    <div class="pane-scroll min-h-0 flex-1 px-4 pb-24">
        @forelse ($this->eventsByDay as $date => $events)
            @php $day = Carbon::parse($date, $tz); @endphp

            <h2 class="sticky top-0 z-10 bg-slate-100 py-2 text-sm font-semibold tracking-wide text-slate-500 uppercase dark:bg-slate-950 dark:text-slate-400">
                @php $offset = $this->household()->todayLocal()->diffInDays($day) @endphp
                {{ $offset === 0 ? 'Today' : ($offset === 1 ? 'Tomorrow' : $day->format('D j M')) }}
            </h2>

            <ul class="mb-2 space-y-2">
                @foreach ($events as $event)
                    @php $colour = $event->calendar?->member?->colour ?? '#94a3b8'; @endphp
                    <li>
                        <button type="button" wire:click="$dispatch('edit-event', { eventId: {{ $event->id }} })"
                                class="flex w-full items-center gap-3 rounded-2xl bg-white p-3 text-left dark:bg-slate-900">
                            <span class="w-14 shrink-0 text-sm font-semibold tabular-nums text-slate-500 dark:text-slate-400">
                                {{ $event->all_day ? 'All day' : $event->start_at->timezone($tz)->format('H:i') }}
                            </span>
                            <span class="h-10 w-1 shrink-0 rounded-full" style="background-color: {{ $colour }};"></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium">{{ $event->title }}</span>
                                <span class="block truncate text-sm text-slate-500 dark:text-slate-400">
                                    {{ $event->calendar?->member?->name }}@if ($event->location) · {{ $event->location }} @endif
                                </span>
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @empty
            <div class="grid h-full place-items-center">
                <p class="text-slate-400">Nothing in the next fortnight.</p>
            </div>
        @endforelse

        {{-- Capture inbox --}}
        @php $waiting = \App\Models\CaptureItem::query()
            ->whereHas('capture', fn ($q) => $q->where('household_id', \App\Models\Household::current()->id))
            ->pending()->count(); @endphp

        <a href="{{ route('review') }}" wire:navigate
           class="mt-4 flex touch-target items-center gap-3 rounded-2xl bg-white p-3 dark:bg-slate-900">
            <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400">
                <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M4 4h16v12H8l-4 4z" />
                </svg>
            </span>
            <span class="min-w-0 flex-1">
                <span class="block font-medium">Review</span>
                <span class="block text-sm text-slate-500 dark:text-slate-400">
                    {{ $waiting === 0 ? 'Capture a letter, email or link' : $waiting.' waiting to be checked' }}
                </span>
            </span>
            @if ($waiting > 0)
                <span class="shrink-0 rounded-full bg-blue-600 px-2.5 py-1 text-xs font-bold text-white">{{ $waiting }}</span>
            @endif
        </a>

        @php
            $waitingOnMe = \App\Models\Redemption::where('household_id', $this->household()->id)->pending()->count()
                + \App\Models\ChoreInstance::query()
                    ->whereNotNull('completed_at')
                    ->whereNull('approved_at')
                    ->whereHas('chore', fn ($q) => $q
                        ->where('household_id', $this->household()->id)
                        ->where('needs_approval', true))
                    ->where('on', $this->household()->todayLocal()->toDateString())
                    ->count();
            $hasChildren = $this->household()->members()->children()->exists();
        @endphp

        @if ($hasChildren)
            <a href="{{ route('kids') }}" wire:navigate
               class="mt-3 flex touch-target items-center gap-3 rounded-2xl bg-white p-3 dark:bg-slate-900">
                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400">
                    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="m12 3 2.9 5.9 6.5.9-4.7 4.6 1.1 6.5-5.8-3-5.8 3 1.1-6.5L2.6 9.8l6.5-.9z" />
                    </svg>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block font-medium">Kids</span>
                    <span class="block text-sm text-slate-500 dark:text-slate-400">
                        {{ $waitingOnMe === 0 ? 'Chores, rewards and the week so far' : trans_choice('{1}:count thing|[2,*]:count things', $waitingOnMe, ['count' => $waitingOnMe]).' waiting for you' }}
                    </span>
                </span>
                @if ($waitingOnMe > 0)
                    <span class="shrink-0 rounded-full bg-amber-500 px-2.5 py-1 text-xs font-bold text-white">{{ $waitingOnMe }}</span>
                @endif
            </a>
        @endif

        @php
            $tonight = \App\Models\Meal::where('household_id', $this->household()->id)
                ->where('slot', 'dinner')
                ->where('on', $this->household()->todayLocal()->toDateString())
                ->first();
        @endphp

        <a href="{{ route('meals') }}" wire:navigate
           class="mt-3 flex touch-target items-center gap-3 rounded-2xl bg-white p-3 dark:bg-slate-900">
            <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-orange-50 text-orange-600 dark:bg-orange-900/30 dark:text-orange-400">
                <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M6 3v9a3 3 0 0 0 6 0V3M9 12v9M17 3c-1.5 2-2 4-2 6s.5 3 2 3 2-1 2-3-.5-4-2-6zm0 9v9" />
                </svg>
            </span>
            <span class="min-w-0 flex-1">
                <span class="block font-medium">Meals</span>
                <span class="block truncate text-sm text-slate-500 dark:text-slate-400">
                    {{ $tonight ? 'Tonight: '.$tonight->title : 'Nothing planned for tonight' }}
                </span>
            </span>
        </a>

        @php
            $shopping = \App\Models\Checklist::where('household_id', $this->household()->id)
                ->where('type', 'shopping')
                ->withCount(['items as outstanding_count' => fn ($q) => $q->where('is_done', false)])
                ->first();
        @endphp

        @if ($shopping)
            <a href="{{ route('shopping') }}" wire:navigate
               class="mt-3 flex touch-target items-center gap-3 rounded-2xl bg-white p-3 dark:bg-slate-900">
                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-purple-50 text-purple-600 dark:bg-purple-900/30 dark:text-purple-400">
                    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M6 6h15l-1.5 9h-12zM6 6 5 3H2M9 20a1 1 0 1 0 0-2 1 1 0 0 0 0 2zm8 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2z" />
                    </svg>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block font-medium">Shopping</span>
                    <span class="block text-sm text-slate-500 dark:text-slate-400">
                        {{ $shopping->outstanding_count === 0
                            ? 'Nothing left to buy'
                            : trans_choice('{1}:count thing to buy|[2,*]:count things to buy', $shopping->outstanding_count, ['count' => $shopping->outstanding_count]) }}
                    </span>
                </span>
            </a>
        @endif

        @php $recipeCount = \App\Models\Recipe::where('household_id', $this->household()->id)->ready()->count(); @endphp

        <a href="{{ route('recipes') }}" wire:navigate
           class="mt-3 flex touch-target items-center gap-3 rounded-2xl bg-white p-3 dark:bg-slate-900">
            <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400">
                <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M6 3v9a3 3 0 0 0 6 0V3M9 12v9M17 3c-1.5 2-2 4-2 6s.5 3 2 3 2-1 2-3-.5-4-2-6zm0 9v9" />
                </svg>
            </span>
            <span class="min-w-0 flex-1">
                <span class="block font-medium">Recipes</span>
                <span class="block text-sm text-slate-500 dark:text-slate-400">
                    {{ $recipeCount === 0 ? 'Save a meal idea' : trans_choice('{1}:count saved idea|[2,*]:count saved ideas', $recipeCount, ['count' => $recipeCount]) }}
                </span>
            </span>
        </a>

        {{-- Editable here: phones are where a to-do actually gets written. --}}
        <section class="mt-4 rounded-2xl bg-white p-3 dark:bg-slate-900">
            <livewire:todos.panel :editable="true" />
        </section>

        <div class="mt-4">
            <livewire:display.lists />
        </div>

        <form method="POST" action="{{ route('logout') }}" class="pt-6">
            @csrf
            <button type="submit" class="touch-target w-full rounded-xl text-sm font-medium text-slate-400">Sign out</button>
        </form>
    </div>

    {{-- Compose. Sits above the scroll area so it is always in thumb reach. --}}
    <button type="button" wire:click="$dispatch('edit-event')"
            class="fixed right-5 bottom-8 z-30 grid size-14 place-items-center rounded-full bg-blue-600 text-white shadow-lg active:bg-blue-700"
            style="margin-bottom: env(safe-area-inset-bottom);"
            aria-label="Add an event">
        <svg class="size-7" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M12 5v14M5 12h14" />
        </svg>
    </button>

    <livewire:phone.event-editor />
</div>
