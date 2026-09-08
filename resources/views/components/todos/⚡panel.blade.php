<?php

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Household;
use App\Models\Member;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The household to-do list, as shown on the wall and on phones.
 *
 * Backed by the ordinary Checklist marked as the household's home list — there
 * is no separate to-do model.
 */
new class extends Component
{
    /** Phones can edit a to-do's details; the wall only ticks and adds. */
    public bool $editable = false;

    /** Seconds a ticked item lingers so it can fade rather than vanish. */
    public const LINGER = 10;

    public bool $adding = false;

    public string $title = '';

    public string $dueOn = '';

    public string $memberId = '';

    /** Blank means "use the household's lead time"; a date overrides it. */
    public string $surfaceFrom = '';

    public ?int $editingId = null;

    public function mount(bool $editable = false): void
    {
        $this->editable = $editable;
    }

    public function list(): Checklist
    {
        return Checklist::home();
    }

    #[Computed]
    public function members(): Collection
    {
        return Household::current()->members;
    }

    /**
     * Open to-dos that have surfaced, plus any ticked in the last few seconds.
     *
     * Keeping just-ticked items in the result is what lets them fade out
     * instead of disappearing the instant Livewire re-renders.
     *
     * Anything still ahead of its surface date is deliberately absent: a form
     * due in six weeks is real work, but it is not today's, and a panel that
     * lists it every day for six weeks stops being read.
     *
     * @return Collection<int, ChecklistItem>
     */
    #[Computed]
    public function todos(): Collection
    {
        return ChecklistItem::query()
            ->where('checklist_id', $this->list()->id)
            ->where(fn ($q) => $q->where('is_done', false)->surfaced()
                ->orWhere('done_at', '>=', now()->subSeconds(self::LINGER)))
            ->with(['member', 'event'])
            ->inDueOrder()
            ->get();
    }

    /** How many are waiting in the wings, so the panel can say so. */
    #[Computed]
    public function upcomingCount(): int
    {
        return ChecklistItem::query()
            ->where('checklist_id', $this->list()->id)
            ->open()
            ->upcoming()
            ->count();
    }

    #[On('todos-changed')]
    public function refreshTodos(): void
    {
        unset($this->todos, $this->upcomingCount);
    }

    public function toggle(int $itemId): void
    {
        $item = $this->findItem($itemId);

        $item->toggle();

        // Deliberately not unsetting the computed: the item must stay in the
        // list long enough for the tick to be seen and to fade.
        $this->dispatch('todos-changed');
    }

    #[Computed]
    public function leadDays(): int
    {
        return Household::current()->todoLeadDays();
    }

    public function startAdding(): void
    {
        $this->reset(['title', 'dueOn', 'memberId', 'surfaceFrom', 'editingId']);
        $this->adding = true;
    }

    public function edit(int $itemId): void
    {
        if (! $this->editable) {
            return;
        }

        $item = $this->findItem($itemId);

        $this->editingId = $item->id;
        $this->title = $item->title;
        $this->dueOn = $item->due_on?->toDateString() ?? '';
        $this->surfaceFrom = $item->surface_from?->toDateString() ?? '';
        $this->memberId = (string) ($item->member_id ?? '');
        $this->adding = true;
    }

    public function save(): void
    {
        $this->validate([
            'title' => 'required|string|max:200',
            'dueOn' => 'nullable|date',
            'surfaceFrom' => 'nullable|date',
            'memberId' => 'nullable|integer',
        ]);

        $attributes = [
            'title' => trim($this->title),
            'due_on' => $this->dueOn !== '' ? $this->dueOn : null,
            // An override with no due date has nothing to count back from.
            'surface_from' => $this->dueOn !== '' && $this->surfaceFrom !== '' ? $this->surfaceFrom : null,
            'member_id' => $this->memberId !== '' ? (int) $this->memberId : null,
        ];

        if ($this->editingId) {
            $this->findItem($this->editingId)->update($attributes);
        } else {
            ChecklistItem::create($attributes + ['checklist_id' => $this->list()->id]);
        }

        $this->reset(['adding', 'title', 'dueOn', 'surfaceFrom', 'memberId', 'editingId']);
        unset($this->todos, $this->upcomingCount);

        $this->dispatch('todos-changed');
    }

    public function deleteItem(int $itemId): void
    {
        if (! $this->editable) {
            return;
        }

        $this->findItem($itemId)->delete();

        $this->reset(['adding', 'title', 'dueOn', 'surfaceFrom', 'memberId', 'editingId']);
        unset($this->todos, $this->upcomingCount);

        $this->dispatch('todos-changed');
    }

    protected function findItem(int $id): ChecklistItem
    {
        return ChecklistItem::query()
            ->whereHas('checklist', fn ($q) => $q->where('household_id', Household::current()->id))
            ->findOrFail($id);
    }
}; ?>

@php
    $today = $this->list()->household->todayLocal();
@endphp

<div class="flex min-h-0 flex-col">
    <div class="flex shrink-0 items-center justify-between gap-2 px-1 pb-1">
        <h2 class="text-sm font-semibold tracking-wide text-slate-400 uppercase">To do</h2>
        <button
            type="button"
            wire:click="startAdding"
            class="grid touch-target place-items-center rounded-lg text-blue-600 dark:text-blue-400"
            aria-label="Add a to-do"
        >
            <svg class="size-6" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" viewBox="0 0 24 24" aria-hidden="true">
                <path d="M12 5v14M5 12h14" />
            </svg>
        </button>
    </div>

    {{-- Quick add / edit.
         A dialog rather than an inline form: inline, it pushed the panel down
         over the wall's tab bar and squeezed the list out of sight. <x-modal>
         keeps it inside the visible viewport, clear of the tab bar, and
         following the keyboard up on iOS. --}}
    @if ($adding)
        <x-modal dismiss="$set('adding', false)" :label="$editingId ? 'Edit to-do' : 'Add a to-do'">
            <form wire:submit="save" data-todo-dialog class="space-y-3 p-4">
                <h3 class="text-lg font-semibold">{{ $editingId ? 'Edit to-do' : 'New to-do' }}</h3>

                <input
                    wire:model="title"
                    type="text"
                    autofocus
                    placeholder="What needs doing?"
                    class="w-full rounded-xl border border-slate-300 px-4 py-3 text-lg dark:border-slate-600 dark:bg-slate-950"
                >
                @error('title') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                <div class="flex flex-wrap gap-2">
                    <label class="min-w-0 flex-1">
                        <span class="block text-sm font-medium text-slate-500 dark:text-slate-400">Due</span>
                        <input
                            wire:model="dueOn"
                            type="date"
                            class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-3 text-base dark:border-slate-600 dark:bg-slate-950"
                        >
                    </label>
                    <label class="min-w-0 flex-1">
                        <span class="block text-sm font-medium text-slate-500 dark:text-slate-400">Who for</span>
                        <select
                            wire:model="memberId"
                            class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-3 text-base dark:border-slate-600 dark:bg-slate-950"
                        >
                            <option value="">Anyone</option>
                            @foreach ($this->members as $member)
                                <option value="{{ $member->id }}">{{ $member->name }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                {{-- Only offered once there is a deadline to count back from,
                     and only on phones: choosing a date is fiddly on the wall
                     and the household default is nearly always right. --}}
                @if ($editable && $dueOn !== '')
                    <label class="block">
                        <span class="block text-sm font-medium text-slate-500 dark:text-slate-400">Start showing it from</span>
                        <input
                            wire:model="surfaceFrom"
                            type="date"
                            class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-3 text-base dark:border-slate-600 dark:bg-slate-950"
                        >
                        <span class="mt-1 block text-xs text-slate-400">
                            Leave blank to show it {{ $this->leadDays }} {{ Str::plural('day', $this->leadDays) }} before it is due.
                        </span>
                    </label>
                @endif

                <div class="flex gap-2 pt-1">
                    <button type="submit" class="touch-target flex-1 rounded-xl bg-blue-600 text-lg font-semibold text-white">
                        {{ $editingId ? 'Save' : 'Add' }}
                    </button>
                    <button type="button" wire:click="$set('adding', false)"
                            class="touch-target rounded-xl px-4 font-semibold text-slate-500">Cancel</button>
                    @if ($editingId && $editable)
                        <button type="button" wire:click="deleteItem({{ $editingId }})"
                                class="touch-target rounded-xl px-4 font-semibold text-red-600">Delete</button>
                    @endif
                </div>
            </form>
        </x-modal>
    @endif

    <ul class="pane-scroll min-h-0 flex-1">
        @forelse ($this->todos as $item)
            @php $overdue = $item->isOverdue($today); @endphp

            <li
                wire:key="todo-{{ $item->id }}"
                x-data="{
                    done: @js($item->is_done),
                    gone: false,
                    timer: null,
                    tick() {
                        this.done = ! this.done;
                        $wire.toggle({{ $item->id }});

                        clearTimeout(this.timer);

                        /* Ticked items linger just long enough to register as
                           done, then fade out of the panel. */
                        if (this.done) {
                            this.timer = setTimeout(() => (this.gone = true), 3500);
                        }
                    },
                }"
                x-show="! gone"
                x-transition.opacity.duration.600ms
            >
                <div class="flex items-center gap-2">
                    <button type="button" x-on:click="tick()" class="flex min-w-0 flex-1 touch-target items-center gap-3 rounded-xl px-1 text-left">
                        <span
                            class="grid size-6 shrink-0 place-items-center rounded-lg border-2 transition-colors"
                            :class="done ? 'border-blue-600 bg-blue-600 text-white' : '{{ $overdue ? 'border-red-400' : 'border-slate-300 dark:border-slate-600' }}'"
                        >
                            <svg x-show="done" class="size-4" fill="none" stroke="currentColor" stroke-width="3"
                                 stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="m5 12 5 5L20 7" />
                            </svg>
                        </span>

                        @if ($item->member)
                            <span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $item->member->colour }};"
                                  title="{{ $item->member->name }}"></span>
                        @endif

                        <span class="min-w-0 flex-1" :class="done && 'text-slate-400 line-through'">
                            <span class="block truncate">{{ $item->title }}</span>
                            <x-todo-due :item="$item" :today="$today" />
                        </span>
                    </button>

                    @if ($editable)
                        <button type="button" wire:click="edit({{ $item->id }})"
                                class="grid touch-target shrink-0 place-items-center rounded-lg text-slate-400"
                                aria-label="Edit {{ $item->title }}">
                            <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z" />
                            </svg>
                        </button>
                    @endif
                </div>
            </li>
        @empty
            <li class="px-1 py-3 text-sm text-slate-400">Nothing to do.</li>
        @endforelse

        {{-- Held-back to-dos are on the Lists tab, not gone. Saying so is what
             makes it safe to keep them off the wall. --}}
        @if ($this->upcomingCount > 0)
            <li class="px-1 py-2 text-xs text-slate-400">
                {{ $this->upcomingCount }} more due later — under Upcoming on Lists.
            </li>
        @endif
    </ul>
</div>
