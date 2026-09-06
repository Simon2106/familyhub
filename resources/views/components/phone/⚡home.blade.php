<?php

use App\Models\Event;
use App\Models\Household;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
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

    public function household(): Household
    {
        return Household::current();
    }

    #[Computed]
    public function members(): Collection
    {
        return $this->household()->members()->get();
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

    <div class="pane-scroll min-h-0 flex-1 px-4 pb-4">
        @forelse ($this->eventsByDay as $date => $events)
            @php $day = Carbon::parse($date, $tz); @endphp

            <h2 class="sticky top-0 z-10 bg-slate-100 py-2 text-sm font-semibold tracking-wide text-slate-500 uppercase dark:bg-slate-950 dark:text-slate-400">
                @php $offset = $this->household()->todayLocal()->diffInDays($day) @endphp
                {{ $offset === 0 ? 'Today' : ($offset === 1 ? 'Tomorrow' : $day->format('D j M')) }}
            </h2>

            <ul class="mb-2 space-y-2">
                @foreach ($events as $event)
                    @php $colour = $event->calendar?->member?->colour ?? '#94a3b8'; @endphp
                    <li class="flex items-center gap-3 rounded-2xl bg-white p-3 dark:bg-slate-900">
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
                    </li>
                @endforeach
            </ul>
        @empty
            <div class="grid h-full place-items-center">
                <p class="text-slate-400">Nothing in the next fortnight.</p>
            </div>
        @endforelse

        <livewire:display.lists />

        <form method="POST" action="{{ route('logout') }}" class="pt-6">
            @csrf
            <button type="submit" class="touch-target w-full rounded-xl text-sm font-medium text-slate-400">Sign out</button>
        </form>
    </div>
</div>
