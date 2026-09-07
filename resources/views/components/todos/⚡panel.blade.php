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
     * Open to-dos, plus any ticked in the last few seconds.
     *
     * Keeping just-ticked items in the result is what lets them fade out
     * instead of disappearing the instant Livewire re-renders.
     *
     * @return Collection<int, ChecklistItem>
     */
    #[Computed]
    public function todos(): Collection
    {
        return ChecklistItem::query()
            ->where('checklist_id', $this->list()->id)
            ->where(fn ($q) => $q->where('is_done', false)
                ->orWhere('done_at', '>=', now()->subSeconds(self::LINGER)))
            ->with('member')
            ->inDueOrder()
            ->get();
    }

    #[On('todos-changed')]
    public function refreshTodos(): void
    {
        unset($this->todos);
    }

    public function toggle(int $itemId): void
    {
        $item = $this->findItem($itemId);

        $item->toggle();

        // Deliberately not unsetting the computed: the item must stay in the
        // list long enough for the tick to be seen and to fade.
        $this->dispatch('todos-changed');
    }

    public function startAdding(): void
    {
        $this->reset(['title', 'dueOn', 'memberId', 'editingId']);
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
        $this->memberId = (string) ($item->member_id ?? '');
        $this->adding = true;
    }

    public function save(): void
    {
        $this->validate([
            'title' => 'required|string|max:200',
            'dueOn' => 'nullable|date',
            'memberId' => 'nullable|integer',
        ]);

        $attributes = [
            'title' => trim($this->title),
            'due_on' => $this->dueOn !== '' ? $this->dueOn : null,
            'member_id' => $this->memberId !== '' ? (int) $this->memberId : null,
        ];

        if ($this->editingId) {
            $this->findItem($this->editingId)->update($attributes);
        } else {
            ChecklistItem::create($attributes + ['checklist_id' => $this->list()->id]);
        }

        $this->reset(['adding', 'title', 'dueOn', 'memberId', 'editingId']);
        unset($this->todos);

        $this->dispatch('todos-changed');
    }

    public function deleteItem(int $itemId): void
    {
        if (! $this->editable) {
            return;
        }

        $this->findItem($itemId)->delete();

        $this->reset(['adding', 'title', 'dueOn', 'memberId', 'editingId']);
        unset($this->todos);

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

    {{-- Quick add / edit. Deliberately large: this gets typed on an iPad
         on-screen keyboard, standing up, at arm's length. --}}
    @if ($adding)
        <form wire:submit="save" class="mb-2 shrink-0 space-y-2 rounded-xl bg-slate-50 p-2 dark:bg-slate-800/70">
            <input
                wire:model="title"
                type="text"
                autofocus
                placeholder="What needs doing?"
                class="w-full rounded-xl border border-slate-300 px-4 py-3 text-lg dark:border-slate-600 dark:bg-slate-900"
            >
            @error('title') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

            <div class="flex gap-2">
                <input
                    wire:model="dueOn"
                    type="date"
                    aria-label="Due date"
                    class="min-w-0 flex-1 rounded-xl border border-slate-300 px-3 py-3 text-base dark:border-slate-600 dark:bg-slate-900"
                >
                <select
                    wire:model="memberId"
                    aria-label="Who for"
                    class="min-w-0 flex-1 rounded-xl border border-slate-300 px-3 py-3 text-base dark:border-slate-600 dark:bg-slate-900"
                >
                    <option value="">Anyone</option>
                    @foreach ($this->members as $member)
                        <option value="{{ $member->id }}">{{ $member->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex gap-2">
                <button type="submit" class="touch-target flex-1 rounded-xl bg-blue-600 font-semibold text-white">
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
    @endif

    <ul class="pane-scroll min-h-0 flex-1">
        @forelse ($this->todos as $item)
            @php
                $overdue = $item->isOverdue($today);
                $dueLabel = match (true) {
                    $item->due_on === null => null,
                    $item->due_on->isSameDay($today) => 'Today',
                    $item->due_on->isSameDay($today->addDay()) => 'Tomorrow',
                    default => $item->due_on->format('D j M'),
                };
            @endphp

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
                            @if ($dueLabel)
                                <span class="block text-sm {{ $overdue ? 'font-semibold text-red-600 dark:text-red-400' : 'text-slate-400' }}">
                                    {{ $overdue ? 'Overdue — '.$dueLabel : $dueLabel }}
                                </span>
                            @endif
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
    </ul>
</div>
