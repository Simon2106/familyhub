<?php

use App\Models\ChecklistItem;
use App\Models\Household;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Household lists on the wall. Ticking is optimistic in Alpine so the checkbox
 * responds under the finger; Livewire only persists afterwards.
 */
new class extends Component
{
    #[Computed]
    public function checklists(): Collection
    {
        return Household::current()
            ->checklists()
            ->with(['items' => fn ($q) => $q->with('member')])
            ->get();
    }

    /**
     * Re-read after a tick anywhere else.
     *
     * Without this, these lists only refreshed when a parent component
     * happened to re-render them — true on the wall, where the wall listens
     * for this event, and false on a phone, where it does not. An item ticked
     * on the To do panel then stayed "outstanding" here indefinitely.
     */
    #[On('todos-changed')]
    public function refreshLists(): void
    {
        unset($this->checklists);
    }

    public function toggle(int $itemId): void
    {
        $item = ChecklistItem::query()
            ->whereHas('checklist', fn ($q) => $q->where('household_id', Household::current()->id))
            ->findOrFail($itemId);

        $item->toggle();

        unset($this->checklists);

        // And the other way round: the home To do panel must follow a tick made here.
        $this->dispatch('todos-changed');
    }

    #[Computed]
    public function retentionDays(): int
    {
        return Household::current()->doneRetentionDays();
    }

    public function clearDone(int $checklistId): void
    {
        ChecklistItem::query()
            ->where('checklist_id', $checklistId)
            ->whereHas('checklist', fn ($q) => $q->where('household_id', Household::current()->id))
            ->where('is_done', true)
            ->delete();

        unset($this->checklists);

        $this->dispatch('todos-changed');
    }
}; ?>

<div class="pane-scroll h-full">
    @if ($this->checklists->isEmpty())
        <div class="grid h-full place-items-center">
            <p class="text-slate-400">No lists yet.</p>
        </div>
    @else
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->checklists as $checklist)
                <section class="rounded-2xl bg-white p-3 dark:bg-slate-900" wire:key="list-{{ $checklist->id }}">
                    <header class="flex items-center gap-2 px-1 pb-2">
                        <h2 class="flex-1 truncate text-base font-semibold">{{ $checklist->name }}</h2>
                    </header>

                    @php
                        $openItems = $checklist->items->where('is_done', false);
                        $doneItems = $checklist->items->where('is_done', true)->sortByDesc('done_at');
                    @endphp

                    <ul>
                        @foreach ($openItems as $item)
                            <li wire:key="item-{{ $item->id }}">
                                <button
                                    type="button"
                                    x-data="{ done: @js($item->is_done) }"
                                    x-on:click="done = !done; $wire.toggle({{ $item->id }})"
                                    class="flex w-full touch-target items-center gap-3 rounded-xl px-1 text-left"
                                >
                                    <span
                                        class="grid size-7 shrink-0 place-items-center rounded-lg border-2 transition-colors"
                                        :class="done
                                            ? 'border-blue-600 bg-blue-600 text-white'
                                            : 'border-slate-300 dark:border-slate-600'"
                                    >
                                        <svg x-show="done" class="size-4" fill="none" stroke="currentColor" stroke-width="3"
                                             stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="m5 12 5 5L20 7" />
                                        </svg>
                                    </span>

                                    <span class="min-w-0 flex-1" :class="done && 'text-slate-400 line-through'">
                                        <span class="block truncate">{{ $item->title }}</span>
                                        @if ($item->quantity)
                                            <span class="block text-sm text-slate-400">{{ $item->quantity }}</span>
                                        @endif
                                    </span>
                                </button>
                            </li>
                        @endforeach

                        @if ($openItems->isEmpty())
                            <li class="px-1 py-3 text-sm text-slate-400">Nothing left on this list.</li>
                        @endif
                    </ul>

                    {{-- Ticked items fade off the wall's To do panel; this is
                         where they can be found again. --}}
                    @if ($doneItems->isNotEmpty())
                        <div x-data="{ open: false }" class="mt-2 border-t border-slate-100 pt-1 dark:border-slate-800">
                            <div class="flex items-center gap-2">
                                <button type="button" x-on:click="open = ! open"
                                        class="flex min-w-0 flex-1 touch-target items-center gap-2 rounded-lg px-1 text-left text-sm font-semibold text-slate-400">
                                    <svg class="size-4 transition-transform" :class="open && 'rotate-90'" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="m9 6 6 6-6 6" />
                                    </svg>
                                    Done ({{ $doneItems->count() }})
                                </button>

                                {{-- Sits on the Done row, which is where someone
                                     looking to clear them actually looks. --}}
                                <button
                                    type="button"
                                    wire:click="clearDone({{ $checklist->id }})"
                                    wire:confirm="Remove the {{ $doneItems->count() }} ticked items from {{ $checklist->name }}?"
                                    class="touch-target shrink-0 rounded-lg px-3 text-sm font-semibold text-blue-600 dark:text-blue-400"
                                >
                                    Clear done
                                </button>
                            </div>

                            <p x-show="open" x-cloak class="px-1 pb-1 text-xs text-slate-400">
                                Cleared automatically after {{ $this->retentionDays }} days.
                            </p>

                            <ul x-show="open" x-cloak x-collapse>
                                @foreach ($doneItems as $item)
                                    <li wire:key="done-{{ $item->id }}">
                                        <button type="button" wire:click="toggle({{ $item->id }})"
                                                class="flex w-full touch-target items-center gap-3 rounded-xl px-1 text-left">
                                            <span class="grid size-7 shrink-0 place-items-center rounded-lg border-2 border-blue-600 bg-blue-600 text-white">
                                                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path d="m5 12 5 5L20 7" />
                                                </svg>
                                            </span>
                                            <span class="min-w-0 flex-1 text-slate-400">
                                                <span class="block truncate line-through">{{ $item->title }}</span>
                                                @if ($item->done_at)
                                                    <span class="block text-sm">{{ $item->done_at->diffForHumans() }}</span>
                                                @endif
                                            </span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </section>
            @endforeach
        </div>
    @endif
</div>
