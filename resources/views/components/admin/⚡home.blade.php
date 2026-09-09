<?php

use App\Exceptions\HomeAssistantException;
use App\Models\Household;
use App\Models\SwitchGroup;
use App\Services\HomeAssistant\SwitchBoard;
use App\Models\HomeTile;
use App\Services\HomeAssistant\HomeAssistant;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Choosing which of Home Assistant's entities belong on the kitchen wall.
 *
 * HA knows about hundreds of things and the wall wants about twelve, so this is
 * a shortlist rather than a mirror. Grouped by HA's own areas, because that is
 * already how the household thinks about the house.
 */
new #[Layout('layouts::app')] class extends Component
{
    public string $search = '';

    public ?string $error = null;

    public ?int $editing = null;

    public string $label = '';

    /** Blank means "whatever the Home Assistant domain implies". */
    public string $section = '';

    /* ------------------------------ groups ------------------------------ */

    public string $newGroup = '';

    public ?int $editingGroup = null;

    public string $groupName = '';

    public int $onDelay = 0;

    public int $offDelay = 0;

    public ?string $onTrigger = null;

    public string $onTime = '17:30';

    public ?string $offTrigger = null;

    public string $offTime = '23:00';

    /** @var list<int> */
    public array $days = [];

    /** @return Collection<int, SwitchGroup> */
    #[Computed]
    public function groups(): Collection
    {
        return app(SwitchBoard::class)->groups($this->household());
    }

    #[Computed]
    public function tracksTheSun(): bool
    {
        try {
            return app(HomeAssistant::class)->tracksTheSun();
        } catch (HomeAssistantException) {
            return false;
        }
    }

    /**
     * Everything a group may contain.
     *
     * Not `available`, which is grouped by area and hides whatever is already
     * a tile — both right for the shortlist and wrong here: a switch can
     * perfectly well be a tile of its own *and* one of the lamps.
     *
     * @return Collection<int, array{entity_id: string, name: string, domain: string, area: ?string}>
     */
    #[Computed]
    public function groupable(): Collection
    {
        try {
            return app(HomeAssistant::class)->pickable()
                ->filter(fn (array $row) => in_array($row['domain'], ['light', 'switch'], true))
                ->values();
        } catch (HomeAssistantException) {
            return collect();
        }
    }

    public function addGroup(): void
    {
        $name = trim($this->newGroup);

        if ($name === '') {
            return;
        }

        $group = SwitchGroup::firstOrCreate([
            'household_id' => $this->household()->id,
            'name' => $name,
        ]);

        $this->newGroup = '';
        $this->editGroup($group->id);

        unset($this->groups);
    }

    public function editGroup(int $id): void
    {
        $group = $this->findGroup($id);

        $this->editingGroup = $this->editingGroup === $id ? null : $id;
        $this->groupName = $group->name;
        $this->onDelay = $group->on_delay;
        $this->offDelay = $group->off_delay;
        $this->onTrigger = $group->on_trigger;
        $this->offTrigger = $group->off_trigger;
        $this->onTime = $group->on_time ? substr((string) $group->on_time, 0, 5) : '17:30';
        $this->offTime = $group->off_time ? substr((string) $group->off_time, 0, 5) : '23:00';
        $this->days = array_map('intval', $group->days ?? []);
    }

    public function saveGroup(): void
    {
        if (! $this->editingGroup) {
            return;
        }

        $this->validate([
            'groupName' => 'required|string|max:80',
            'onDelay' => 'required|integer|min:0|max:'.SwitchGroup::MAX_DELAY,
            'offDelay' => 'required|integer|min:0|max:'.SwitchGroup::MAX_DELAY,
            'onTime' => 'required|date_format:H:i',
            'offTime' => 'required|date_format:H:i',
        ]);

        $this->findGroup($this->editingGroup)->update([
            'name' => trim($this->groupName),
            'on_delay' => $this->onDelay,
            'off_delay' => $this->offDelay,
            'on_trigger' => $this->onTrigger ?: null,
            'off_trigger' => $this->offTrigger ?: null,
            'on_time' => $this->onTrigger === 'time' ? $this->onTime : null,
            'off_time' => $this->offTrigger === 'time' ? $this->offTime : null,
            // Sorted and unique: the picker sends strings, and a day listed
            // twice would read as two different Tuesdays.
            'days' => array_values(array_unique(array_map('intval', $this->days))),
        ]);

        unset($this->groups);

        $this->dispatch('saved', message: 'Group saved.');
    }

    public function deleteGroup(int $id): void
    {
        $this->findGroup($id)->delete();

        $this->editingGroup = null;
        unset($this->groups);
    }

    public function addToGroup(int $id, string $entityId): void
    {
        $group = $this->findGroup($id);

        $group->entities()->firstOrCreate(
            ['entity_id' => $entityId],
            ['name' => $this->groupable->firstWhere('entity_id', $entityId)['name'] ?? $entityId],
        );

        unset($this->groups);
    }

    public function removeFromGroup(int $id, string $entityId): void
    {
        $this->findGroup($id)->entities()->where('entity_id', $entityId)->delete();

        unset($this->groups);
    }

    public function toggleDay(int $day): void
    {
        $this->days = in_array($day, $this->days, true)
            ? array_values(array_diff($this->days, [$day]))
            : [...$this->days, $day];
    }

    protected function findGroup(int $id): SwitchGroup
    {
        return SwitchGroup::where('household_id', $this->household()->id)
            ->with('entities')
            ->findOrFail($id);
    }

    public function household(): Household
    {
        return Household::current();
    }

    #[Computed]
    public function configured(): bool
    {
        return app(HomeAssistant::class)->isConfigured();
    }

    /** @return Collection<int, HomeTile> */
    #[Computed]
    public function chosen(): Collection
    {
        return HomeTile::where('household_id', $this->household()->id)
            ->orderBy('area')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Everything HA offers, minus what is already chosen, grouped by room.
     *
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    #[Computed]
    public function available(): Collection
    {
        if (! $this->configured) {
            return collect();
        }

        try {
            $entities = app(HomeAssistant::class)->pickable();
        } catch (HomeAssistantException $e) {
            $this->error = $e->getMessage();

            return collect();
        }

        $already = $this->chosen->pluck('entity_id')->all();
        $needle = trim(mb_strtolower($this->search));

        return $entities
            ->reject(fn (array $row) => in_array($row['entity_id'], $already, true))
            ->filter(fn (array $row) => $needle === ''
                || str_contains(mb_strtolower($row['name']), $needle)
                || str_contains(mb_strtolower($row['entity_id']), $needle)
                || str_contains(mb_strtolower((string) $row['area']), $needle))
            ->groupBy(fn (array $row) => $row['area'] ?: HomeTile::UNGROUPED);
    }

    public function add(string $entityId): void
    {
        $this->error = null;

        $entity = $this->available->flatten(1)->firstWhere('entity_id', $entityId);

        if (! $entity) {
            return;
        }

        HomeTile::updateOrCreate(
            ['household_id' => $this->household()->id, 'entity_id' => $entityId],
            [
                'domain' => $entity['domain'],
                'name' => $entity['name'],
                // Re-read from HA each time the picker is opened, so a room
                // renamed over there is picked up on the next visit.
                'area' => $entity['area'],
                'sort_order' => ($this->chosen->max('sort_order') ?? 0) + 1,
            ],
        );

        unset($this->chosen, $this->available);
    }

    public function remove(int $id): void
    {
        $this->tile($id)->delete();

        $this->editing = null;
        unset($this->chosen, $this->available);
    }

    public function startEditing(int $id): void
    {
        $tile = $this->tile($id);

        $this->editing = $tile->id;
        $this->label = $tile->label ?: '';
        $this->section = (string) $tile->section_override;
    }

    public function saveTile(): void
    {
        $this->validate([
            'label' => 'nullable|string|max:60',
            'section' => 'nullable|in:'.implode(',', array_keys(HomeTile::SECTIONS)),
        ]);

        $this->tile((int) $this->editing)->update([
            'label' => trim($this->label) ?: null,
            // Null rather than the derived value, so a tile nobody has had an
            // opinion about follows the defaults if those ever change.
            'section_override' => $this->section ?: null,
        ]);

        $this->reset(['editing', 'label', 'section']);
        unset($this->chosen);
    }

    /** Pull fresh names and rooms for everything already chosen. */
    public function resync(): void
    {
        $this->error = null;

        try {
            $entities = app(HomeAssistant::class)->pickable()->keyBy('entity_id');
        } catch (HomeAssistantException $e) {
            $this->error = $e->getMessage();

            return;
        }

        foreach ($this->chosen as $tile) {
            $entity = $entities->get($tile->entity_id);

            // An entity removed from HA keeps its tile rather than vanishing:
            // the household should be told, not quietly corrected.
            if ($entity) {
                $tile->update(['name' => $entity['name'], 'area' => $entity['area']]);
            }
        }

        unset($this->chosen, $this->available);

        $this->dispatch('saved', message: 'Names and rooms brought up to date.');
    }

    protected function tile(int $id): HomeTile
    {
        return HomeTile::where('household_id', $this->household()->id)->findOrFail($id);
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
        <h1 class="flex-1 text-2xl font-bold">Home</h1>
        @if ($this->configured && $this->chosen->isNotEmpty())
            <button type="button" wire:click="resync"
                    class="touch-target rounded-xl px-3 font-semibold text-blue-600 dark:text-blue-400">Refresh</button>
        @endif
    </header>

    <div x-data="{ show: false, message: '' }"
         x-on:saved.window="message = $event.detail.message; show = true; setTimeout(() => show = false, 2500)"
         x-show="show" x-cloak x-transition
         class="fixed inset-x-4 top-4 z-50 rounded-xl bg-slate-900 px-4 py-3 text-white shadow-lg dark:bg-white dark:text-slate-900">
        <span x-text="message"></span>
    </div>

    <div class="pane-scroll min-h-0 flex-1 space-y-4 px-4 pb-8">

        @unless ($this->configured)
            <div class="rounded-2xl bg-white p-4 dark:bg-slate-900">
                <h2 class="font-semibold">Home Assistant is not connected</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    Add <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">HA_URL</code> and
                    <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">HA_TOKEN</code> to
                    <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">.env</code>.
                    The URL is the address you already use, port and all —
                    <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">http://homeassistant.local</code>
                    is as valid as one with <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">:8123</code>.
                    The token comes from your HA profile, under Long-lived access tokens.
                </p>
            </div>
        @endunless

        @if ($error)
            <p class="rounded-xl bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">{{ $error }}</p>
        @endif

        {{-- ---------------------------- GROUPS ---------------------------- --}}
        @if ($this->configured)
            <section class="rounded-2xl bg-white p-4 dark:bg-slate-900">
                <h2 class="font-semibold">Groups</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    A handful of switches the household thinks of as one thing. Each group
                    gets its own tile at the top of Switches, and its own idea of what on
                    and off mean — instantly, or after a few minutes.
                </p>

                <form wire:submit="addGroup" class="mt-3 flex gap-2">
                    <input wire:model="newGroup" type="text" placeholder="Lamps"
                           class="min-w-0 flex-1 rounded-xl border border-slate-300 px-3 py-2 text-base dark:border-slate-600 dark:bg-slate-950">
                    <button type="submit" class="touch-target shrink-0 rounded-xl bg-blue-600 px-4 font-semibold text-white">Add</button>
                </form>

                <ul class="mt-3 space-y-2">
                    @foreach ($this->groups as $group)
                        <li wire:key="admin-group-{{ $group->id }}"
                            class="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                            <button type="button" wire:click="editGroup({{ $group->id }})"
                                    class="flex w-full items-center gap-3 text-left">
                                <span class="min-w-0 flex-1">
                                    <span class="block font-medium">{{ $group->name }}</span>
                                    <span class="block text-sm text-slate-500 dark:text-slate-400">
                                        {{ $group->entities->count() }} {{ Str::plural('switch', $group->entities->count()) }}
                                        · on {{ $group->isInstant('on') ? 'instantly' : 'after '.$group->on_delay.' min' }}
                                        · off {{ $group->isInstant('off') ? 'instantly' : 'after '.$group->off_delay.' min' }}
                                        @if ($group->scheduled('on') || $group->scheduled('off')) · scheduled @endif
                                    </span>
                                </span>
                                <span class="shrink-0 text-sm font-semibold text-blue-600 dark:text-blue-400">
                                    {{ $editingGroup === $group->id ? 'Done' : 'Edit' }}
                                </span>
                            </button>

                            @if ($editingGroup === $group->id)
                                <div class="mt-3 space-y-3 border-t border-slate-100 pt-3 dark:border-slate-800">
                                    <label class="block">
                                        <span class="block text-sm font-medium">Name</span>
                                        <input wire:model="groupName" type="text"
                                               class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-base dark:border-slate-600 dark:bg-slate-950">
                                    </label>
                                    @error('groupName') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                                    {{-- What is in it. --}}
                                    <div>
                                        <p class="text-sm font-medium">Switches in this group</p>
                                        <ul class="mt-1 space-y-1">
                                            @forelse ($group->entities as $member)
                                                <li class="flex items-center gap-2" wire:key="ge-{{ $member->id }}">
                                                    <span class="min-w-0 flex-1 truncate text-sm">{{ $member->title() }}</span>
                                                    <button type="button" wire:click="removeFromGroup({{ $group->id }}, '{{ $member->entity_id }}')"
                                                            class="touch-target rounded-xl px-3 text-sm font-semibold text-red-600">Remove</button>
                                                </li>
                                            @empty
                                                <li class="text-sm text-slate-400">Nothing in it yet.</li>
                                            @endforelse
                                        </ul>

                                        <select wire:change="addToGroup({{ $group->id }}, $event.target.value)"
                                                class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2 text-base dark:border-slate-600 dark:bg-slate-950">
                                            <option value="">Add a switch…</option>
                                            @foreach ($this->groupable as $entity)
                                                @if (! $group->entities->contains('entity_id', $entity['entity_id']))
                                                    <option value="{{ $entity['entity_id'] }}">{{ $entity['name'] }}@if ($entity['area']) · {{ $entity['area'] }} @endif</option>
                                                @endif
                                            @endforeach
                                        </select>
                                    </div>

                                    {{-- The two directions, which are rarely the same. --}}
                                    <div class="flex gap-2">
                                        @foreach (['onDelay' => 'On after', 'offDelay' => 'Off after'] as $field => $label)
                                            <label class="min-w-0 flex-1">
                                                <span class="block text-sm font-medium">{{ $label }}</span>
                                                <input wire:model="{{ $field }}" type="number" min="0" max="{{ \App\Models\SwitchGroup::MAX_DELAY }}"
                                                       class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-base dark:border-slate-600 dark:bg-slate-950">
                                            </label>
                                        @endforeach
                                    </div>
                                    <p class="text-sm text-slate-500 dark:text-slate-400">Minutes. 0 switches straight away.</p>

                                    {{-- Schedules. --}}
                                    @foreach (['on' => ['onTrigger', 'onTime', 'Turn on'], 'off' => ['offTrigger', 'offTime', 'Turn off']] as $direction => [$triggerField, $timeField, $label])
                                        <div class="flex flex-wrap items-end gap-2">
                                            <label class="min-w-0 flex-1">
                                                <span class="block text-sm font-medium">{{ $label }}</span>
                                                <select wire:model.live="{{ $triggerField }}"
                                                        class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-base dark:border-slate-600 dark:bg-slate-950">
                                                    <option value="">Never</option>
                                                    @foreach (\App\Models\SwitchGroup::TRIGGERS as $key => $triggerLabel)
                                                        @if ($key === 'time' || $this->tracksTheSun)
                                                            <option value="{{ $key }}">{{ $triggerLabel }}</option>
                                                        @endif
                                                    @endforeach
                                                </select>
                                            </label>

                                            @if ($$triggerField === 'time')
                                                <input wire:model="{{ $timeField }}" type="time"
                                                       class="touch-target rounded-xl border border-slate-300 px-3 dark:border-slate-600 dark:bg-slate-950">
                                            @endif
                                        </div>
                                    @endforeach

                                    @unless ($this->tracksTheSun)
                                        <p class="text-sm text-slate-400">
                                            Sunrise and sunset appear here once Home Assistant is tracking the sun.
                                        </p>
                                    @endunless

                                    @if ($onTrigger || $offTrigger)
                                        <div>
                                            <p class="text-sm font-medium">On these days</p>
                                            <div class="mt-1 flex flex-wrap gap-1">
                                                @foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $day => $dayLabel)
                                                    <button type="button" wire:click="toggleDay({{ $day }})"
                                                            class="touch-target rounded-xl px-3 text-sm font-semibold {{ in_array($day, $days, true) ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' }}">
                                                        {{ $dayLabel }}
                                                    </button>
                                                @endforeach
                                            </div>
                                            <p class="mt-1 text-sm text-slate-400">None chosen means every day.</p>
                                        </div>
                                    @endif

                                    <div class="flex gap-2">
                                        <button type="button" wire:click="saveGroup"
                                                class="touch-target flex-1 rounded-xl bg-blue-600 font-semibold text-white">Save</button>
                                        <button type="button" wire:click="deleteGroup({{ $group->id }})"
                                                wire:confirm="Delete the {{ $group->name }} group? The switches themselves are untouched."
                                                class="touch-target rounded-xl px-4 text-sm font-semibold text-red-600">Delete</button>
                                    </div>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- ---------------------------- CHOSEN ---------------------------- --}}
        @if ($this->chosen->isNotEmpty())
            <section class="rounded-2xl bg-white p-4 dark:bg-slate-900">
                <h2 class="font-semibold">On the wall</h2>

                {{-- Grouped the way the wall groups them, so this page is a
                     preview of it rather than a different filing system. --}}
                @foreach ($this->chosen->groupBy(fn ($tile) => $tile->section()) as $group => $tiles)
                    <p class="mt-3 text-xs font-semibold tracking-wide text-slate-400 uppercase">{{ HomeTile::SECTIONS[$group] }}</p>

                    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($tiles as $tile)
                            <li class="py-2" wire:key="tile-{{ $tile->id }}">
                                @if ($editing === $tile->id)
                                    <form wire:submit="saveTile" class="space-y-2">
                                        <input wire:model="label" type="text" autofocus
                                               placeholder="{{ $tile->name }}"
                                               class="touch-target w-full rounded-xl border border-slate-300 px-3 dark:border-slate-700 dark:bg-slate-950">

                                        <label class="block">
                                            <span class="block text-sm font-medium">Show under</span>
                                            <select wire:model="section"
                                                    class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-3 dark:border-slate-700 dark:bg-slate-950">
                                                <option value="">
                                                    {{ HomeTile::SECTIONS[HomeTile::defaultSectionFor($tile->domain)] }} (from Home Assistant)
                                                </option>
                                                @foreach (HomeTile::SECTIONS as $key => $label)
                                                    <option value="{{ $key }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            <span class="mt-1 block text-sm text-slate-500 dark:text-slate-400">
                                                Only where it appears on the wall. What tapping it does still follows
                                                Home Assistant — a plug filed under Lights is still a plug.
                                            </span>
                                        </label>

                                        <div class="flex gap-2">
                                            <button type="submit" class="touch-target flex-1 rounded-xl bg-blue-600 px-4 font-semibold text-white">Save</button>
                                            <button type="button" wire:click="$set('editing', null)"
                                                    class="touch-target shrink-0 rounded-xl px-3 font-semibold text-slate-500">Cancel</button>
                                        </div>
                                    </form>
                                @else
                                    <div class="flex items-center gap-3">
                                        <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-slate-100 text-base dark:bg-slate-800" aria-hidden="true">
                                            <x-ha-icon :domain="$tile->domain" />
                                        </span>
                                        <button type="button" wire:click="startEditing({{ $tile->id }})" class="min-w-0 flex-1 text-left">
                                            <span class="block truncate font-medium">{{ $tile->title() }}</span>
                                            <span class="block truncate text-sm text-slate-400">
                                                {{ $tile->entity_id }}@if ($tile->area) · {{ $tile->area }} @endif
                                            </span>
                                        </button>
                                        <button type="button" wire:click="remove({{ $tile->id }})"
                                                class="shrink-0 touch-target rounded-xl px-3 text-sm font-semibold text-red-600">Remove</button>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endforeach
            </section>
        @endif

        {{-- --------------------------- AVAILABLE -------------------------- --}}
        @if ($this->configured)
            <section class="rounded-2xl bg-white p-4 dark:bg-slate-900">
                <h2 class="font-semibold">Add something</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    Lights, switches, thermostats, blinds, scenes and scripts. Everything else in
                    Home Assistant is deliberately left out.
                </p>

                <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search by name, room or entity id"
                       class="touch-target mt-3 w-full rounded-xl border border-slate-300 px-4 dark:border-slate-700 dark:bg-slate-950">

                @forelse ($this->available as $room => $entities)
                    <p class="mt-3 text-xs font-semibold tracking-wide text-slate-400 uppercase">{{ $room }}</p>

                    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($entities as $entity)
                            <li wire:key="avail-{{ $entity['entity_id'] }}">
                                <button type="button" wire:click="add('{{ $entity['entity_id'] }}')"
                                        class="flex w-full touch-target items-center gap-3 py-2 text-left">
                                    <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-slate-100 text-base dark:bg-slate-800" aria-hidden="true">
                                        <x-ha-icon :domain="$entity['domain']" />
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate font-medium">{{ $entity['name'] }}</span>
                                        <span class="block truncate text-sm text-slate-400">{{ $entity['entity_id'] }}</span>
                                    </span>
                                    <span class="shrink-0 text-lg font-bold text-blue-600 dark:text-blue-400" aria-hidden="true">+</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @empty
                    <p class="mt-3 text-sm text-slate-400">
                        {{ $search !== '' ? 'Nothing matches that.' : 'Everything Home Assistant offers is already on the wall.' }}
                    </p>
                @endforelse
            </section>
        @endif
    </div>
</div>
