<?php

use App\Models\Chore;
use App\Models\Household;
use App\Models\Member;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Setting up the standing arrangements: what, who, how often, what it is worth. */
new #[Layout('layouts::app')] class extends Component
{
    public ?int $editingId = null;

    public string $title = '';

    public string $icon = '';

    public string $memberId = '';

    public string $recurrence = 'daily';

    /** @var list<int> */
    public array $days = [];

    /** Weekly is one day, so it is a single choice rather than a set. */
    public string $weeklyDay = '1';

    public int $points = 1;

    public bool $needsApproval = false;

    public bool $isActive = true;

    public const DAY_NAMES = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    #[Computed]
    public function chores(): Collection
    {
        return Chore::query()
            ->where('household_id', Household::current()->id)
            ->with('member')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function members(): Collection
    {
        return Household::current()->members;
    }

    /**
     * Keep the picked days as sorted, unique numbers.
     *
     * A checkbox hands its value back as a string, and the tiles compare
     * strictly against the integer weekday. Without this the array updates
     * perfectly well and no tile ever lights up, so the picker looks broken
     * while working.
     */
    public function updatedDays(): void
    {
        $days = array_values(array_unique(array_map('intval', $this->days)));

        sort($days);

        $this->days = $days;
    }

    public function addChore(): void
    {
        $this->reset(['editingId', 'title', 'icon', 'memberId', 'recurrence', 'days', 'weeklyDay', 'points', 'needsApproval', 'isActive']);
        $this->editingId = 0;
    }

    public function edit(int $id): void
    {
        $chore = $this->find($id);

        $this->editingId = $chore->id;
        $this->title = $chore->title;
        $this->icon = (string) $chore->icon;
        $this->memberId = (string) ($chore->member_id ?? '');
        $this->recurrence = $chore->recurrence;
        $this->days = $chore->recurrence === 'days' ? $chore->weekdays() : [];
        $this->weeklyDay = (string) ($chore->recurrence === 'weekly' ? ($chore->weekdays()[0] ?? 1) : 1);
        $this->points = $chore->points;
        $this->needsApproval = $chore->needs_approval;
        $this->isActive = $chore->is_active;
    }

    public function save(): void
    {
        $this->validate([
            'title' => 'required|string|max:120',
            'icon' => 'nullable|string|max:8',
            'recurrence' => 'required|in:'.implode(',', Chore::RECURRENCES),
            'points' => 'required|integer|min:0|max:1000',
            // Saving a chore that falls due on nothing is worse than refusing
            // it: it looks set up and then never appears.
            'days' => $this->recurrence === 'days' ? 'required|array|min:1' : 'array',
            'days.*' => 'integer|min:1|max:7',
            'weeklyDay' => 'required|integer|min:1|max:7',
        ], [
            'days.required' => 'Pick at least one day.',
            'days.min' => 'Pick at least one day.',
        ]);

        $days = match ($this->recurrence) {
            'weekly' => [(int) $this->weeklyDay],
            'days' => $this->days,
            default => null,
        };

        $attributes = [
            'household_id' => Household::current()->id,
            'title' => trim($this->title),
            'icon' => trim($this->icon) ?: null,
            'member_id' => $this->memberId !== '' ? (int) $this->memberId : null,
            'recurrence' => $this->recurrence,
            'days' => $days,
            'points' => $this->points,
            'needs_approval' => $this->needsApproval,
            'is_active' => $this->isActive,
        ];

        $this->editingId
            ? $this->find($this->editingId)->update($attributes)
            // Dated from today, so a chore added this afternoon does not
            // appear as missed on every day of the week already gone.
            : Chore::create($attributes + ['starts_on' => Household::current()->todayLocal()->toDateString()]);

        $this->reset(['editingId', 'title', 'icon', 'memberId', 'recurrence', 'days', 'weeklyDay', 'points', 'needsApproval', 'isActive']);
        unset($this->chores);

        $this->dispatch('saved', message: 'Chore saved.');
    }

    public function deleteChore(int $id): void
    {
        $this->find($id)->delete();

        $this->reset(['editingId']);
        unset($this->chores);

        $this->dispatch('saved', message: 'Chore removed.');
    }

    protected function find(int $id): Chore
    {
        return Chore::where('household_id', Household::current()->id)->findOrFail($id);
    }
}; ?>

<div class="app-shell flex flex-col">
    <header class="flex shrink-0 items-center gap-3 px-4 pt-4 pb-2">
        <a href="{{ route('admin') }}" wire:navigate
           class="grid touch-target place-items-center rounded-xl bg-white text-slate-500 dark:bg-slate-900" aria-label="Back">
            <svg class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                <path d="m15 18-6-6 6-6" />
            </svg>
        </a>
        <h1 class="flex-1 text-2xl font-bold">Chores</h1>
        <button type="button" wire:click="addChore"
                class="touch-target rounded-xl bg-blue-600 px-4 font-semibold text-white">Add</button>
    </header>

    <div x-data="{ show: false, message: '' }"
         x-on:saved.window="message = $event.detail.message; show = true; setTimeout(() => show = false, 2500)"
         x-show="show" x-cloak x-transition
         class="fixed inset-x-4 top-4 z-50 rounded-xl bg-slate-900 px-4 py-3 text-white shadow-lg dark:bg-white dark:text-slate-900">
        <span x-text="message"></span>
    </div>

    <div class="pane-scroll min-h-0 flex-1 space-y-3 px-4 pb-8">
        @if ($editingId !== null)
            <x-modal dismiss="$set('editingId', null)" :label="$editingId ? 'Edit chore' : 'New chore'" width="max-w-md">
            <form wire:submit="save" class="space-y-3 rounded-2xl bg-white p-4 dark:bg-slate-900">
                <h2 class="font-semibold">{{ $editingId ? 'Edit chore' : 'New chore' }}</h2>

                <div class="flex gap-2">
                    <label class="w-20 shrink-0">
                        <span class="block text-sm font-medium">Icon</span>
                        <input wire:model="icon" type="text" placeholder="🐱" maxlength="8"
                               class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-3 text-center text-lg dark:border-slate-700 dark:bg-slate-950">
                    </label>
                    <label class="min-w-0 flex-1">
                        <span class="block text-sm font-medium">What</span>
                        <input wire:model="title" type="text" placeholder="Feed the cat"
                               class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-4 dark:border-slate-700 dark:bg-slate-950">
                    </label>
                </div>
                @error('title') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                <div class="flex flex-wrap gap-2">
                    <label class="min-w-0 flex-1">
                        <span class="block text-sm font-medium">Who</span>
                        <select wire:model="memberId"
                                class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-3 dark:border-slate-700 dark:bg-slate-950">
                            <option value="">Anyone</option>
                            @foreach ($this->members as $member)
                                <option value="{{ $member->id }}">{{ $member->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="w-28 shrink-0">
                        <span class="block text-sm font-medium">Points</span>
                        <input wire:model="points" type="number" inputmode="numeric" min="0" max="1000"
                               class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-3 text-center dark:border-slate-700 dark:bg-slate-950">
                    </label>
                </div>

                <div>
                    <span class="block text-sm font-medium">How often</span>
                    <div class="mt-1 grid grid-cols-4 gap-1 rounded-xl bg-slate-100 p-1 dark:bg-slate-800">
                        @foreach (['daily' => 'Every day', 'weekdays' => 'School days', 'weekly' => 'Weekly', 'days' => 'Pick days'] as $key => $label)
                            <button type="button" wire:click="$set('recurrence', '{{ $key }}')"
                                    class="touch-target rounded-lg text-xs font-semibold {{ $recurrence === $key ? 'bg-white shadow-sm dark:bg-slate-900' : 'text-slate-500' }}">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>

                    @if ($recurrence === 'days')
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach (self::DAY_NAMES as $number => $name)
                                <label class="grid size-12 cursor-pointer place-items-center rounded-xl border-2 text-sm font-semibold transition-colors focus-within:ring-2 focus-within:ring-blue-500 focus-within:ring-offset-2 dark:focus-within:ring-offset-slate-900
                                              {{ in_array($number, $days, true) ? 'border-blue-600 bg-blue-50 text-blue-700 dark:border-blue-500 dark:bg-blue-950 dark:text-blue-300' : 'border-slate-200 text-slate-500 dark:border-slate-700' }}">
                                    <input type="checkbox" wire:model.live="days" value="{{ $number }}" class="sr-only">
                                    {{ $name }}
                                </label>
                            @endforeach
                        </div>
                        @error('days') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    @elseif ($recurrence === 'weekly')
                        {{-- Radios, not checkboxes: a weekly chore is one day,
                             and letting several be ticked only to quietly use
                             one of them is a worse answer than not offering it. --}}
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach (self::DAY_NAMES as $number => $name)
                                <label class="grid size-12 cursor-pointer place-items-center rounded-xl border-2 text-sm font-semibold transition-colors focus-within:ring-2 focus-within:ring-blue-500 focus-within:ring-offset-2 dark:focus-within:ring-offset-slate-900
                                              {{ (int) $weeklyDay === $number ? 'border-blue-600 bg-blue-50 text-blue-700 dark:border-blue-500 dark:bg-blue-950 dark:text-blue-300' : 'border-slate-200 text-slate-500 dark:border-slate-700' }}">
                                    <input type="radio" wire:model.live="weeklyDay" value="{{ $number }}" class="sr-only">
                                    {{ $name }}
                                </label>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="flex flex-wrap gap-4">
                    <label class="flex touch-target items-center gap-2">
                        <input wire:model="needsApproval" type="checkbox" class="size-5 rounded">
                        <span class="text-sm font-medium">A grown-up checks it</span>
                    </label>
                    <label class="flex touch-target items-center gap-2">
                        <input wire:model="isActive" type="checkbox" class="size-5 rounded">
                        <span class="text-sm font-medium">Active</span>
                    </label>
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="touch-target flex-1 rounded-xl bg-blue-600 font-semibold text-white">Save</button>
                    <button type="button" wire:click="$set('editingId', null)"
                            class="touch-target rounded-xl px-4 font-semibold text-slate-500">Cancel</button>
                    @if ($editingId)
                        <button type="button" wire:click="deleteChore({{ $editingId }})"
                                wire:confirm="Remove this chore? Points already earned are kept."
                                class="touch-target rounded-xl px-4 font-semibold text-red-600">Delete</button>
                    @endif
                </div>
            </form>
        </x-modal>
        @endif

        @forelse ($this->chores as $chore)
            <button type="button" wire:click="edit({{ $chore->id }})" wire:key="chore-{{ $chore->id }}"
                    class="flex w-full touch-target items-center gap-3 rounded-2xl bg-white p-3 text-left dark:bg-slate-900 {{ $chore->is_active ? '' : 'opacity-50' }}">
                <span class="grid size-10 shrink-0 place-items-center rounded-xl text-lg"
                      style="background-color: {{ $chore->member?->colour ?? '#94a3b8' }}22;">
                    {{ $chore->icon ?: '✓' }}
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-medium">{{ $chore->title }}</span>
                    <span class="block truncate text-sm text-slate-500 dark:text-slate-400">
                        {{ $chore->member?->name ?? 'Anyone' }} · {{ $chore->scheduleLabel() }}@if ($chore->needs_approval) · checked @endif
                        @if (! $chore->is_active) · paused @endif
                    </span>
                </span>
                @if ($chore->points > 0)
                    <span class="shrink-0 rounded-full bg-slate-100 px-2.5 py-1 text-sm font-bold tabular-nums dark:bg-slate-800">{{ $chore->points }}</span>
                @endif
            </button>
        @empty
            <div class="rounded-2xl border-2 border-dashed border-slate-200 p-10 text-center dark:border-slate-800">
                <p class="font-semibold text-slate-400">No chores yet.</p>
                <p class="mt-1 text-sm text-slate-400">Add one and it will appear in that child's column on the wall.</p>
            </div>
        @endforelse
    </div>
</div>
