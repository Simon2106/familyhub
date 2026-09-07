<?php

use App\Models\Household;
use App\Models\Member;
use App\Models\Place;
use App\Services\Attribution\EventAttributor;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Schools, workplaces and clubs — the named organisations that turn up in event
 * titles, and who each one concerns.
 */
new #[Layout('layouts::app')] class extends Component
{
    /** null when the form is closed, 0 when adding, otherwise the place id. */
    public ?int $editingId = null;

    public string $name = '';

    public string $type = 'school';

    public string $aliases = '';

    /** member id => attached */
    public array $attached = [];

    /** member id => pulled in automatically when this place matches */
    public array $automatic = [];

    #[Computed]
    public function places(): Collection
    {
        return Household::current()
            ->places()
            ->with(['aliases', 'members'])
            ->get();
    }

    #[Computed]
    public function members(): Collection
    {
        return Household::current()->members;
    }

    public function add(): void
    {
        $this->reset(['name', 'type', 'aliases', 'attached', 'automatic']);
        $this->editingId = 0;
    }

    public function edit(int $id): void
    {
        $place = $this->findPlace($id);

        $this->editingId = $place->id;
        $this->name = $place->name;
        $this->type = $place->type;
        $this->aliases = $place->aliases->pluck('alias')->join(', ');

        $this->attached = [];
        $this->automatic = [];

        foreach ($place->members as $member) {
            $this->attached[$member->id] = true;
            $this->automatic[$member->id] = (bool) $member->pivot->include_automatically;
        }
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:120',
            'type' => 'required|in:'.implode(',', Place::TYPES),
            'aliases' => 'nullable|string|max:500',
        ]);

        $place = $this->editingId
            ? $this->findPlace($this->editingId)
            : new Place(['household_id' => Household::current()->id]);

        $place->fill([
            'household_id' => Household::current()->id,
            'name' => trim($this->name),
            'type' => $this->type,
        ]);
        $place->save();

        $this->syncAliases($place);

        // Attach only the ticked members, each carrying its own automatic flag.
        $place->members()->sync(
            collect($this->attached)
                ->filter()
                ->mapWithKeys(fn ($_, $memberId) => [
                    (int) $memberId => ['include_automatically' => (bool) ($this->automatic[$memberId] ?? false)],
                ])
                ->all()
        );

        $this->reset(['editingId', 'name', 'type', 'aliases', 'attached', 'automatic']);
        unset($this->places);

        $changed = app(EventAttributor::class)->applyToHousehold(Household::current());

        $this->dispatch('saved', message: "Saved. {$changed} events re-attributed.");
    }

    public function deletePlace(int $id): void
    {
        $this->findPlace($id)->delete();

        $this->reset(['editingId']);
        unset($this->places);

        app(EventAttributor::class)->applyToHousehold(Household::current());

        $this->dispatch('saved', message: 'Place removed. Attribution re-run.');
    }

    /** Attaching a member defaults them to being included automatically. */
    public function updatedAttached($value, $key): void
    {
        if ($value && ! isset($this->automatic[$key])) {
            $this->automatic[$key] = true;
        }
    }

    protected function syncAliases(Place $place): void
    {
        $wanted = collect(explode(',', $this->aliases))
            ->map(fn (string $a) => trim(preg_replace('/\s+/u', ' ', $a) ?? ''))
            ->filter()
            ->unique(fn (string $a) => mb_strtolower($a))
            ->values();

        $place->aliases()->whereNotIn('alias', $wanted->all())->delete();

        foreach ($wanted as $alias) {
            $place->aliases()->firstOrCreate(['alias' => $alias]);
        }
    }

    protected function findPlace(int $id): Place
    {
        return Place::where('household_id', Household::current()->id)
            ->with(['aliases', 'members'])
            ->findOrFail($id);
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
        <h1 class="text-2xl font-bold">Places</h1>
    </header>

    <div class="pane-scroll min-h-0 flex-1 space-y-4 px-4 pb-8">
        <div x-data="{ show: false, message: '' }"
             x-on:saved.window="message = $event.detail.message; show = true; setTimeout(() => show = false, 3000)"
             x-show="show" x-cloak x-transition
             class="fixed inset-x-4 top-4 z-50 rounded-xl bg-slate-900 px-4 py-3 text-white shadow-lg dark:bg-white dark:text-slate-900">
            <span x-text="message"></span>
        </div>

        <p class="text-sm text-slate-500 dark:text-slate-400">
            A school, workplace or club named in an event title tells FamilyHub who the event is for.
            Sandy Gate on a title puts it in Sienna's column.
        </p>

        @forelse ($this->places as $place)
            <section class="rounded-2xl bg-white p-4 dark:bg-slate-900" wire:key="place-{{ $place->id }}">
                <div class="flex items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <h2 class="truncate font-semibold">{{ $place->name }}</h2>
                        <p class="truncate text-sm text-slate-500 dark:text-slate-400">
                            {{ $place->typeLabel() }}@if ($place->aliases->isNotEmpty()) · also {{ $place->aliases->pluck('alias')->join(', ') }} @endif
                        </p>
                    </div>
                    <button type="button" wire:click="edit({{ $place->id }})"
                            class="touch-target shrink-0 rounded-xl px-3 text-sm font-semibold text-blue-600 dark:text-blue-400">Edit</button>
                </div>

                @if ($place->members->isNotEmpty())
                    <ul class="mt-2 flex flex-wrap gap-1.5">
                        @foreach ($place->members as $member)
                            <li class="flex items-center gap-1.5 rounded-full px-2.5 py-1 text-sm"
                                style="background-color: {{ $member->colour }}1a;">
                                <span class="size-2 rounded-full" style="background-color: {{ $member->colour }};"></span>
                                {{ $member->name }}
                                @unless ($member->pivot->include_automatically)
                                    <span class="text-slate-400">(by name only)</span>
                                @endunless
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-2 text-sm text-amber-700 dark:text-amber-400">Not linked to anyone yet, so it matches nobody.</p>
                @endif
            </section>
        @empty
            <p class="rounded-2xl bg-white p-6 text-center text-slate-500 dark:bg-slate-900 dark:text-slate-400">
                No places yet.
            </p>
        @endforelse

        @if ($editingId !== null)
            <form wire:submit="save" class="space-y-3 rounded-2xl bg-white p-4 dark:bg-slate-900">
                <h2 class="font-semibold">{{ $editingId ? 'Edit place' : 'Add a place' }}</h2>

                <div>
                    <label class="block text-sm font-medium" for="place-name">Name</label>
                    <input wire:model="name" id="place-name" type="text" placeholder="Sandy Gate"
                           class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-4 dark:border-slate-700 dark:bg-slate-950">
                    @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium" for="place-type">Type</label>
                    <select wire:model="type" id="place-type"
                            class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-3 dark:border-slate-700 dark:bg-slate-950">
                        @foreach (\App\Models\Place::TYPES as $option)
                            <option value="{{ $option }}">{{ ucfirst($option) }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium" for="place-aliases">Also matches</label>
                    <input wire:model="aliases" id="place-aliases" type="text" placeholder="SG, Sandy Gate Primary"
                           class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-4 dark:border-slate-700 dark:bg-slate-950">
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        Short forms used in event titles, separated by commas. The name itself always matches.
                    </p>
                </div>

                <fieldset>
                    <legend class="text-sm font-medium">Who it concerns</legend>

                    <ul class="mt-1 divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($this->members as $member)
                            <li class="py-1" wire:key="place-member-{{ $member->id }}">
                                <label class="flex touch-target items-center gap-3">
                                    <input type="checkbox" wire:model.live="attached.{{ $member->id }}" class="size-5 rounded">
                                    <span class="size-3 shrink-0 rounded-full" style="background-color: {{ $member->colour }};"></span>
                                    <span class="min-w-0 flex-1 truncate">{{ $member->name }}</span>
                                </label>

                                @if ($attached[$member->id] ?? false)
                                    <label class="flex touch-target items-center justify-between gap-3 pl-11">
                                        <span class="text-sm text-slate-500 dark:text-slate-400">Include automatically</span>
                                        <input type="checkbox" wire:model="automatic.{{ $member->id }}" class="size-5 rounded">
                                    </label>
                                    @unless ($automatic[$member->id] ?? false)
                                        <p class="pl-11 text-sm text-slate-400">
                                            Added only when their own name is in the title too.
                                        </p>
                                    @endunless
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </fieldset>

                <div class="flex gap-2">
                    <button type="submit" class="touch-target flex-1 rounded-xl bg-blue-600 font-semibold text-white">Save</button>
                    <button type="button" wire:click="$set('editingId', null)" class="touch-target rounded-xl px-4 font-semibold text-slate-500">Cancel</button>
                    @if ($editingId)
                        <button type="button" wire:click="deletePlace({{ $editingId }})"
                                wire:confirm="Remove this place? Events already attributed through it will be re-attributed."
                                class="touch-target rounded-xl px-4 font-semibold text-red-600">Delete</button>
                    @endif
                </div>
            </form>
        @else
            <button type="button" wire:click="add"
                    class="touch-target w-full rounded-2xl border-2 border-dashed border-slate-300 font-semibold text-slate-500 dark:border-slate-700 dark:text-slate-400">
                Add a place
            </button>
        @endif
    </div>
</div>
