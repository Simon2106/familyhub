<?php

use App\Exceptions\HomeAssistantException;
use App\Models\Household;
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
