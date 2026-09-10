<?php

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Household;
use App\Models\Member;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Every list the household keeps.
 *
 * Shopping and To do were only ever two of these; the table has held a name, a
 * colour and a sort order all along. What a packing list needs that they do
 * not is somebody whose it is, and a way to be made and unmade without a
 * migration.
 */
new #[Layout('layouts::app')] class extends Component
{
    /** Which list is open. Kept in the URL so a link to one lands on it. */
    #[Url(as: 'list', except: 0)]
    public int $openListId = 0;

    public bool $managing = false;

    /* ------------------------------ the list ----------------------------- */

    public string $listName = '';

    public string $listColour = '#2563eb';

    public string $listOwner = '';

    public bool $onTheWall = false;

    public ?int $editingListId = null;

    /* ------------------------------ an item ------------------------------ */

    public string $itemTitle = '';

    public ?string $problem = null;

    public function household(): Household
    {
        return Household::current();
    }

    /** @return Collection<int, Checklist> */
    #[Computed]
    public function lists(): Collection
    {
        return $this->household()->checklists()
            ->with('member')
            ->withCount(['items as outstanding' => fn ($q) => $q->where('is_done', false)])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function open(): ?Checklist
    {
        $lists = $this->lists;

        return $lists->firstWhere('id', $this->openListId) ?? $lists->first();
    }

    /** @return Collection<int, ChecklistItem> */
    #[Computed]
    public function items(): Collection
    {
        return $this->open
            ? $this->open->items()->with('member')->orderBy('is_done')->orderBy('sort_order')->orderBy('id')->get()
            : collect();
    }

    /** @return Collection<int, Member> */
    #[Computed]
    public function members(): Collection
    {
        return $this->household()->members()->orderBy('name')->get();
    }

    #[On('todos-changed')]
    public function refreshLists(): void
    {
        unset($this->lists, $this->open, $this->items);
    }

    /* ------------------------------ writing ------------------------------ */

    public function show(int $id): void
    {
        $this->openListId = $id;
        $this->managing = false;

        unset($this->open, $this->items);
    }

    public function newList(): void
    {
        $this->editingListId = null;
        $this->listName = '';
        $this->listColour = '#2563eb';
        $this->listOwner = '';
        $this->onTheWall = false;
        $this->managing = true;
    }

    public function editList(int $id): void
    {
        $list = $this->find($id);

        $this->editingListId = $list->id;
        $this->listName = $list->name;
        $this->listColour = $list->colour;
        $this->listOwner = (string) ($list->member_id ?? '');
        $this->onTheWall = (bool) $list->is_home_list;
        $this->managing = true;
    }

    public function saveList(): void
    {
        $this->validate([
            'listName' => 'required|string|max:60',
            'listColour' => 'required|string|max:7',
        ]);

        $attributes = [
            'name' => trim($this->listName),
            'colour' => $this->listColour,
            'member_id' => $this->listOwner !== '' ? (int) $this->listOwner : null,
            'is_home_list' => $this->onTheWall,
        ];

        if ($this->editingListId) {
            $this->find($this->editingListId)->update($attributes);
        } else {
            $list = Checklist::create($attributes + [
                'household_id' => $this->household()->id,
                // Anything made here is a list somebody wanted, not one the
                // app depends on — so it can be deleted again.
                'type' => 'custom',
                'sort_order' => ($this->lists->max('sort_order') ?? 0) + 1,
            ]);

            $this->openListId = $list->id;
        }

        $this->managing = false;
        $this->refreshLists();
        $this->dispatch('saved', message: 'List saved.');
    }

    public function deleteList(int $id): void
    {
        $list = $this->find($id);

        // Shopping and To do stay: half the app points at them, and losing one
        // would take the capture pipeline's to-dos with it.
        if ($list->isBuiltIn()) {
            $this->problem = 'Shopping and To do cannot be removed — the rest of the app uses them.';

            return;
        }

        $list->delete();

        $this->openListId = 0;
        $this->managing = false;
        $this->refreshLists();
    }

    public function addItem(): void
    {
        $title = trim($this->itemTitle);

        if ($title === '' || ! $this->open) {
            return;
        }

        $this->open->items()->create([
            'title' => $title,
            'sort_order' => ((int) $this->open->items()->max('sort_order')) + 1,
        ]);

        $this->itemTitle = '';
        $this->refreshLists();
        $this->dispatch('todos-changed');
    }

    public function toggleItem(int $id): void
    {
        $this->findItem($id)->toggle();

        $this->refreshLists();
        $this->dispatch('todos-changed');
    }

    public function deleteItem(int $id): void
    {
        $this->findItem($id)->delete();

        $this->refreshLists();
        $this->dispatch('todos-changed');
    }

    /**
     * Move an item up or down.
     *
     * Swapping with its neighbour rather than renumbering the list: a packing
     * list somebody has dragged into a sensible order should not be rewritten
     * from top to bottom every time one thing moves.
     */
    public function move(int $id, int $by): void
    {
        $items = $this->items->values();
        $at = $items->search(fn (ChecklistItem $item) => $item->id === $id);

        if ($at === false) {
            return;
        }

        $to = $at + $by;

        if ($to < 0 || $to >= $items->count()) {
            return;
        }

        $moving = $items[$at];
        $other = $items[$to];

        // The stored orders may both be zero on a list nobody has sorted, so
        // positions are taken from where things actually sit.
        $moving->forceFill(['sort_order' => $to])->save();
        $other->forceFill(['sort_order' => $at])->save();

        foreach ($items as $index => $item) {
            if ($item->id !== $moving->id && $item->id !== $other->id) {
                $item->forceFill(['sort_order' => $index])->save();
            }
        }

        $this->refreshLists();
    }

    protected function find(int $id): Checklist
    {
        return Checklist::where('household_id', $this->household()->id)->findOrFail($id);
    }

    protected function findItem(int $id): ChecklistItem
    {
        return ChecklistItem::whereHas('checklist', fn ($q) => $q->where('household_id', $this->household()->id))
            ->findOrFail($id);
    }
}; ?>

<div class="app-shell flex flex-col">
    <header class="shrink-0 px-4 pt-4 pb-2">
        <div class="flex items-center gap-3">
            <a href="{{ route('app') }}" wire:navigate
               class="grid touch-target place-items-center rounded-xl bg-white text-slate-500 dark:bg-slate-900" aria-label="Back">
                <svg class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="m15 18-6-6 6-6" />
                </svg>
            </a>
            <h1 class="flex-1 text-2xl font-bold">Lists</h1>
            <button type="button" wire:click="newList"
                    class="touch-target rounded-xl bg-blue-600 px-4 font-semibold text-white">New list</button>
        </div>

        {{-- The lists themselves, as a rail. Colour is the whole point of
             them: at a glance you are looking for the green one. --}}
        <div class="pane-scroll -mx-4 mt-3 flex gap-2 overflow-x-auto px-4 pb-1">
            @foreach ($this->lists as $list)
                <button type="button" wire:click="show({{ $list->id }})"
                        wire:key="list-{{ $list->id }}"
                        class="flex touch-target shrink-0 items-center gap-2 rounded-full px-4 text-sm font-semibold {{ $this->open?->id === $list->id ? 'text-white' : 'bg-white text-slate-600 dark:bg-slate-900 dark:text-slate-300' }}"
                        @style(["background-color: {$list->colour}" => $this->open?->id === $list->id])>
                    <span class="size-2.5 shrink-0 rounded-full"
                          style="background-color: {{ $this->open?->id === $list->id ? '#fff' : $list->colour }};"></span>
                    {{ $list->name }}
                    @if ($list->outstanding > 0)
                        <span class="opacity-60">{{ $list->outstanding }}</span>
                    @endif
                </button>
            @endforeach
        </div>
    </header>

    <div class="shrink-0 px-4 pb-2">
        <livewire:search.box />
    </div>

    <div x-data="{ show: false, message: '' }"
         x-on:saved.window="message = $event.detail.message; show = true; setTimeout(() => show = false, 2500)"
         x-show="show" x-cloak x-transition
         class="fixed inset-x-4 top-4 z-50 rounded-xl bg-slate-900 px-4 py-3 text-white shadow-lg dark:bg-white dark:text-slate-900">
        <span x-text="message"></span>
    </div>

    <div class="pane-scroll min-h-0 flex-1 px-4 pb-8">
        @if ($problem)
            <p class="mb-3 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">{{ $problem }}</p>
        @endif

        @if ($this->open)
            <div class="mb-3 flex items-center gap-2">
                <h2 class="min-w-0 flex-1 truncate text-lg font-semibold">
                    {{ $this->open->name }}
                    @if ($this->open->member)
                        <span class="text-sm font-normal text-slate-500 dark:text-slate-400">· {{ $this->open->member->name }}</span>
                    @endif
                </h2>
                <button type="button" wire:click="editList({{ $this->open->id }})"
                        class="touch-target rounded-xl px-3 text-sm font-semibold text-blue-600 dark:text-blue-400">Edit</button>
            </div>

            <form wire:submit="addItem" class="mb-3 flex gap-2">
                <input wire:model="itemTitle" type="text" placeholder="Add something"
                       class="min-w-0 flex-1 rounded-xl border border-slate-200 bg-white px-4 py-3 text-base dark:border-slate-700 dark:bg-slate-900">
                <button type="submit" class="touch-target shrink-0 rounded-xl bg-blue-600 px-5 font-semibold text-white">Add</button>
            </form>

            <ul class="space-y-1">
                @forelse ($this->items as $item)
                    <li class="flex items-center gap-2 rounded-2xl bg-white p-2 dark:bg-slate-900" wire:key="item-{{ $item->id }}">
                        <button type="button" wire:click="toggleItem({{ $item->id }})"
                                class="grid size-11 shrink-0 place-items-center rounded-xl"
                                aria-label="{{ $item->is_done ? 'Not done after all' : 'Done' }}">
                            <span class="grid size-6 place-items-center rounded-lg border-2 {{ $item->is_done ? 'border-transparent text-white' : 'border-slate-300 dark:border-slate-600' }}"
                                  @style(["background-color: {$this->open->colour}" => $item->is_done])>
                                @if ($item->is_done)
                                    <x-icon name="check" class="size-4" />
                                @endif
                            </span>
                        </button>

                        <span class="min-w-0 flex-1 {{ $item->is_done ? 'text-slate-400 line-through' : '' }}">
                            <span class="block truncate">{{ $item->title }}</span>
                            @if ($item->quantity)
                                <span class="block text-sm text-slate-400">{{ $item->quantity }}</span>
                            @endif
                        </span>

                        {{-- Up and down rather than a drag: a list on a phone
                             is scrolled far more often than it is reordered,
                             and a drag that starts by accident loses your
                             place. --}}
                        <button type="button" wire:click="move({{ $item->id }}, -1)"
                                class="grid size-11 shrink-0 place-items-center rounded-xl text-slate-400" aria-label="Move up">
                            <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 15 6-6 6 6" /></svg>
                        </button>
                        <button type="button" wire:click="move({{ $item->id }}, 1)"
                                class="grid size-11 shrink-0 place-items-center rounded-xl text-slate-400" aria-label="Move down">
                            <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
                        </button>
                        <button type="button" wire:click="deleteItem({{ $item->id }})"
                                class="grid size-11 shrink-0 place-items-center rounded-xl text-slate-300 dark:text-slate-600" aria-label="Remove">
                            <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" /></svg>
                        </button>
                    </li>
                @empty
                    <li class="rounded-2xl border-2 border-dashed border-slate-200 p-8 text-center dark:border-slate-700">
                        <p class="font-semibold text-slate-400">Nothing on this list.</p>
                        <p class="mt-1 text-sm text-slate-400">Add the first thing above.</p>
                    </li>
                @endforelse
            </ul>
        @else
            <div class="rounded-2xl border-2 border-dashed border-slate-200 p-8 text-center dark:border-slate-700">
                <p class="font-semibold text-slate-400">No lists yet.</p>
            </div>
        @endif
    </div>

    @if ($managing)
        <x-modal dismiss="$set('managing', false)" :label="$editingListId ? 'Edit list' : 'New list'">
            <x-slot:header>
                <h3 class="p-4 pb-2 text-lg font-semibold">{{ $editingListId ? 'Edit list' : 'New list' }}</h3>
            </x-slot:header>

            <form id="save-list" wire:submit="saveList" class="space-y-3 px-4 pb-4">
                <label class="block">
                    <span class="block text-sm font-medium">Name</span>
                    <input wire:model="listName" type="text" placeholder="Packing"
                           class="mt-1 w-full rounded-xl border border-slate-300 px-4 py-3 text-base dark:border-slate-600 dark:bg-slate-950">
                </label>
                @error('listName') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                <label class="block">
                    <span class="block text-sm font-medium">Colour</span>
                    <input wire:model="listColour" type="color"
                           class="mt-1 h-11 w-full rounded-xl border border-slate-300 dark:border-slate-600">
                </label>

                <label class="block">
                    <span class="block text-sm font-medium">Whose is it?</span>
                    <select wire:model="listOwner"
                            class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-3 dark:border-slate-600 dark:bg-slate-950">
                        <option value="">Everyone's</option>
                        @foreach ($this->members as $member)
                            <option value="{{ $member->id }}">{{ $member->name }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="flex touch-target items-center justify-between gap-3">
                    <span class="min-w-0">
                        <span class="block text-sm font-medium">Show on the wall</span>
                        <span class="block text-sm text-slate-500 dark:text-slate-400">In the Lists tab of the display.</span>
                    </span>
                    <input wire:model="onTheWall" type="checkbox" class="size-6 shrink-0 rounded">
                </label>
            </form>

            <x-slot:footer>
                <div class="flex gap-2 border-t border-slate-100 p-4 pt-3 dark:border-slate-800">
                    <button type="submit" form="save-list"
                            class="touch-target flex-1 rounded-xl bg-blue-600 text-lg font-semibold text-white">Save</button>

                    @if ($editingListId && ! $this->lists->firstWhere('id', $editingListId)?->isBuiltIn())
                        <button type="button" wire:click="deleteList({{ $editingListId }})"
                                wire:confirm="Delete this list and everything on it?"
                                class="touch-target rounded-xl px-4 text-sm font-semibold text-red-600">Delete</button>
                    @endif

                    <button type="button" wire:click="$set('managing', false)"
                            class="touch-target rounded-xl px-4 font-semibold text-slate-500">Cancel</button>
                </div>
            </x-slot:footer>
        </x-modal>
    @endif
</div>
