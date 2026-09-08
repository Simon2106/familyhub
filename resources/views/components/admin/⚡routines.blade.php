<?php

use App\Models\Household;
use App\Models\Routine;
use App\Models\RoutineStep;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Setting up the morning, after-school and bedtime sequences. */
new #[Layout('layouts::app')] class extends Component
{
    public ?int $editingId = null;

    public string $memberId = '';

    public string $kind = 'morning';

    public string $name = '';

    public string $startsAt = '07:00';

    public string $endsAt = '08:30';

    public bool $isActive = true;

    /** Being added to an existing routine. */
    public ?int $addingStepTo = null;

    public string $stepTitle = '';

    public string $stepIcon = '';

    #[Computed]
    public function routines(): Collection
    {
        return Routine::query()
            ->where('household_id', Household::current()->id)
            ->with(['member', 'steps'])
            ->orderBy('member_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function children(): Collection
    {
        return Household::current()->members()->children()->get();
    }

    public function addRoutine(): void
    {
        $this->reset(['editingId', 'kind', 'name', 'startsAt', 'endsAt', 'isActive', 'addingStepTo']);
        $this->memberId = (string) ($this->children->first()?->id ?? '');
        $this->editingId = 0;
    }

    public function edit(int $id): void
    {
        $routine = $this->find($id);

        $this->editingId = $routine->id;
        $this->memberId = (string) $routine->member_id;
        $this->kind = $routine->kind;
        $this->name = $routine->name;
        $this->startsAt = substr((string) $routine->starts_at, 0, 5);
        $this->endsAt = substr((string) $routine->ends_at, 0, 5);
        $this->isActive = $routine->is_active;
        $this->addingStepTo = null;
    }

    public function save(): void
    {
        $this->validate([
            'memberId' => 'required|integer',
            'kind' => 'required|in:'.implode(',', array_keys(Routine::KINDS)),
            'name' => 'required|string|max:60',
            'startsAt' => 'required|date_format:H:i',
            'endsAt' => 'required|date_format:H:i',
        ]);

        $attributes = [
            'household_id' => Household::current()->id,
            'member_id' => (int) $this->memberId,
            'kind' => $this->kind,
            'name' => trim($this->name),
            'starts_at' => $this->startsAt.':00',
            'ends_at' => $this->endsAt.':00',
            'is_active' => $this->isActive,
        ];

        $this->editingId
            ? $this->find($this->editingId)->update($attributes)
            : Routine::create($attributes);

        $this->reset(['editingId', 'name']);
        unset($this->routines);

        $this->dispatch('saved', message: 'Routine saved.');
    }

    public function deleteRoutine(int $id): void
    {
        $this->find($id)->delete();

        $this->reset(['editingId']);
        unset($this->routines);

        $this->dispatch('saved', message: 'Routine removed.');
    }

    public function startStep(int $routineId): void
    {
        $this->addingStepTo = $routineId;
        $this->reset(['stepTitle', 'stepIcon']);
    }

    public function addStep(): void
    {
        $this->validate(['stepTitle' => 'required|string|max:60', 'stepIcon' => 'nullable|string|max:8']);

        $routine = $this->find((int) $this->addingStepTo);

        $routine->steps()->create([
            'title' => trim($this->stepTitle),
            'icon' => trim($this->stepIcon) ?: null,
            'sort_order' => ($routine->steps()->max('sort_order') ?? 0) + 1,
        ]);

        $this->reset(['stepTitle', 'stepIcon']);
        unset($this->routines);
    }

    public function deleteStep(int $stepId): void
    {
        RoutineStep::query()
            ->whereHas('routine', fn ($q) => $q->where('household_id', Household::current()->id))
            ->findOrFail($stepId)
            ->delete();

        unset($this->routines);
    }

    protected function find(int $id): Routine
    {
        return Routine::where('household_id', Household::current()->id)->findOrFail($id);
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
        <h1 class="flex-1 text-2xl font-bold">Routines</h1>
        @if ($this->children->isNotEmpty())
            <button type="button" wire:click="addRoutine"
                    class="touch-target rounded-xl bg-blue-600 px-4 font-semibold text-white">Add</button>
        @endif
    </header>

    <div x-data="{ show: false, message: '' }"
         x-on:saved.window="message = $event.detail.message; show = true; setTimeout(() => show = false, 2500)"
         x-show="show" x-cloak x-transition
         class="fixed inset-x-4 top-4 z-50 rounded-xl bg-slate-900 px-4 py-3 text-white shadow-lg dark:bg-white dark:text-slate-900">
        <span x-text="message"></span>
    </div>

    <div class="pane-scroll min-h-0 flex-1 space-y-3 px-4 pb-8">
        @if ($this->children->isEmpty())
            <div class="rounded-2xl border-2 border-dashed border-slate-200 p-10 text-center dark:border-slate-800">
                <p class="font-semibold text-slate-400">No children set up yet.</p>
                <p class="mt-1 text-sm text-slate-400">Mark a family member as a child in Settings first.</p>
            </div>
        @endif

        @if ($editingId !== null)
            <x-modal dismiss="$set('editingId', null)" :label="$editingId ? 'Edit routine' : 'New routine'">
                <form wire:submit="save" class="space-y-3 rounded-2xl bg-white p-4 dark:bg-slate-900">
                <h2 class="font-semibold">{{ $editingId ? 'Edit routine' : 'New routine' }}</h2>

                <div class="flex flex-wrap gap-2">
                    <label class="min-w-0 flex-1">
                        <span class="block text-sm font-medium">Who</span>
                        <select wire:model="memberId" class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-3 dark:border-slate-700 dark:bg-slate-950">
                            @foreach ($this->children as $child)
                                <option value="{{ $child->id }}">{{ $child->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="min-w-0 flex-1">
                        <span class="block text-sm font-medium">When in the day</span>
                        <select wire:model="kind" class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-3 dark:border-slate-700 dark:bg-slate-950">
                            @foreach (\App\Models\Routine::KINDS as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <label class="block">
                    <span class="block text-sm font-medium">Name</span>
                    <input wire:model="name" type="text" placeholder="Morning"
                           class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-4 dark:border-slate-700 dark:bg-slate-950">
                </label>
                @error('name') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                <div class="flex gap-2">
                    <label class="min-w-0 flex-1">
                        <span class="block text-sm font-medium">Shown from</span>
                        <input wire:model="startsAt" type="time" class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-3 dark:border-slate-700 dark:bg-slate-950">
                    </label>
                    <label class="min-w-0 flex-1">
                        <span class="block text-sm font-medium">until</span>
                        <input wire:model="endsAt" type="time" class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-3 dark:border-slate-700 dark:bg-slate-950">
                    </label>
                </div>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Outside this window the routine stays on the child's own page but leaves the wall alone.
                </p>

                <label class="flex touch-target items-center gap-2">
                    <input wire:model="isActive" type="checkbox" class="size-5 rounded">
                    <span class="text-sm font-medium">Active</span>
                </label>

                <div class="flex gap-2">
                    <button type="submit" class="touch-target flex-1 rounded-xl bg-blue-600 font-semibold text-white">Save</button>
                    <button type="button" wire:click="$set('editingId', null)" class="touch-target rounded-xl px-4 font-semibold text-slate-500">Cancel</button>
                    @if ($editingId)
                        <button type="button" wire:click="deleteRoutine({{ $editingId }})"
                                wire:confirm="Remove this routine and its steps?"
                                class="touch-target rounded-xl px-4 font-semibold text-red-600">Delete</button>
                    @endif
                </div>
                </form>
            </x-modal>
        @endif

        @foreach ($this->routines as $routine)
            <section class="rounded-2xl bg-white p-4 dark:bg-slate-900 {{ $routine->is_active ? '' : 'opacity-50' }}"
                     wire:key="routine-{{ $routine->id }}">
                <div class="flex items-center gap-3">
                    <span class="size-3 shrink-0 rounded-full" style="background-color: {{ $routine->member?->colour }};"></span>
                    <button type="button" wire:click="edit({{ $routine->id }})" class="min-w-0 flex-1 text-left">
                        <span class="block truncate font-semibold">{{ $routine->member?->name }} · {{ $routine->label() }}</span>
                        <span class="block text-sm text-slate-500 dark:text-slate-400">
                            {{ $routine->windowLabel() }} · {{ $routine->steps->count() }} steps
                        </span>
                    </button>
                    <button type="button" wire:click="startStep({{ $routine->id }})"
                            class="touch-target shrink-0 rounded-xl px-3 text-sm font-semibold text-blue-600 dark:text-blue-400">Add step</button>
                </div>

                @if ($routine->steps->isNotEmpty())
                    <ul class="mt-2 flex flex-wrap gap-1.5">
                        @foreach ($routine->steps as $step)
                            <li wire:key="step-{{ $step->id }}">
                                <button type="button" wire:click="deleteStep({{ $step->id }})"
                                        wire:confirm="Remove &quot;{{ $step->title }}&quot;?"
                                        class="flex items-center gap-1.5 rounded-full bg-slate-100 py-1.5 pr-2 pl-3 text-sm font-medium dark:bg-slate-800">
                                    @if ($step->icon) <span aria-hidden="true">{{ $step->icon }}</span> @endif
                                    {{ $step->title }}
                                    <span class="text-slate-400" aria-hidden="true">×</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($addingStepTo === $routine->id)
                    <form wire:submit="addStep" class="mt-3 flex gap-2">
                        <input wire:model="stepIcon" type="text" placeholder="🦷" maxlength="8"
                               class="touch-target w-16 rounded-xl border border-slate-300 px-2 text-center text-lg dark:border-slate-700 dark:bg-slate-950">
                        <input wire:model="stepTitle" type="text" placeholder="Brush teeth" autofocus
                               class="touch-target min-w-0 flex-1 rounded-xl border border-slate-300 px-4 dark:border-slate-700 dark:bg-slate-950">
                        <button type="submit" class="touch-target shrink-0 rounded-xl bg-blue-600 px-4 font-semibold text-white">Add</button>
                    </form>
                    @error('stepTitle') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                @endif
            </section>
        @endforeach
    </div>
</div>
