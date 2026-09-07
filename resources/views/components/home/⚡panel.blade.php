<?php

use App\Exceptions\HomeAssistantException;
use App\Models\Household;
use App\Models\HomeTile;
use App\Services\HomeAssistant\EntityState;
use App\Services\HomeAssistant\HomeAssistant;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The Home tab: the household's chosen entities, grouped by room.
 *
 * State is read through a short shared cache that the websocket listener keeps
 * warm, so a wall of tiles is not a wall of requests to a Raspberry Pi. With
 * the listener running these reads never leave the box; without it they fall
 * back to polling REST, which is slower but never wrong for long.
 */
new class extends Component
{
    public ?string $error = null;

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
    public function tiles(): Collection
    {
        return HomeTile::where('household_id', $this->household()->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * One reading of Home Assistant: what it said, or why it did not.
     *
     * Both together, because a computed that sets $error as a side effect only
     * works if the template happens to touch it before it renders the error —
     * which it did not, so an unreachable Pi showed a wall of blank tiles and
     * no explanation.
     *
     * @return array{states: Collection<string, EntityState>, error: ?string}
     */
    #[Computed]
    public function reading(): array
    {
        if (! $this->configured || $this->tiles->isEmpty()) {
            return ['states' => collect(), 'error' => null];
        }

        try {
            return ['states' => app(HomeAssistant::class)->states(), 'error' => null];
        } catch (HomeAssistantException $e) {
            return ['states' => collect(), 'error' => $e->getMessage()];
        }
    }

    /** @return Collection<string, EntityState> */
    #[Computed]
    public function states(): Collection
    {
        return $this->reading['states'];
    }

    /** An action's failure if there was one, otherwise whatever the read said. */
    #[Computed]
    public function problem(): ?string
    {
        return $this->error ?: $this->reading['error'];
    }

    /**
     * Tiles by section, in the order the sections are listed.
     *
     * Grouped by kind rather than by room: on a wall, "turn a light on" is the
     * thing being looked for far more often than "what is in the kitchen", and
     * the room is still on every tile for when it is the question.
     *
     * @return Collection<string, Collection<int, HomeTile>>
     */
    #[Computed]
    public function sections(): Collection
    {
        $grouped = $this->tiles->groupBy(fn (HomeTile $tile) => $tile->section());

        return collect(HomeTile::SECTIONS)
            ->map(fn (string $label, string $section) => $grouped->get($section, collect()))
            ->reject(fn (Collection $tiles) => $tiles->isEmpty());
    }

    public function stateFor(HomeTile $tile): ?EntityState
    {
        return $this->states->get($tile->entity_id);
    }

    /**
     * Not named `tap`: Livewire\Component already has one, and an override
     * with a different signature is a fatal error rather than a quiet
     * shadowing.
     */
    public function press(int $tileId): void
    {
        $this->act($tileId, fn (HomeAssistant $ha, HomeTile $tile) => $ha->toggle($tile->entity_id));
    }

    public function move(int $tileId, string $action): void
    {
        $this->act($tileId, fn (HomeAssistant $ha, HomeTile $tile) => $ha->cover($tile->entity_id, $action));
    }

    public function nudge(int $tileId, float $by): void
    {
        $this->act($tileId, function (HomeAssistant $ha, HomeTile $tile) use ($by) {
            // Nudged from where the thermostat is actually set, not from a
            // number this screen has been holding since it last rendered.
            $current = $this->stateFor($tile)?->targetTemperature() ?? 18.0;

            $ha->setTemperature($tile->entity_id, $current + $by);
        });
    }

    /** Refresh state without waiting for the poll — after a tap, or by hand. */
    public function reread(): void
    {
        $this->error = null;

        try {
            app(HomeAssistant::class)->states(fresh: true);
        } catch (HomeAssistantException $e) {
            $this->error = $e->getMessage();
        }

        unset($this->reading, $this->states, $this->problem);
    }

    /**
     * Do something to one tile, and say so plainly if it did not work.
     *
     * A wall in a kitchen cannot show a stack trace, and a tap that silently
     * does nothing is worse than one that admits the Pi is unreachable.
     *
     * @param  callable(HomeAssistant, HomeTile): void  $do
     */
    protected function act(int $tileId, callable $do): void
    {
        $this->error = null;

        $tile = $this->tiles->firstWhere('id', $tileId);

        if (! $tile) {
            return;
        }

        try {
            $do(app(HomeAssistant::class), $tile);
        } catch (HomeAssistantException $e) {
            $this->error = $e->getMessage();
        }

        unset($this->reading, $this->states, $this->problem);
    }
}; ?>

{{-- Polled rather than pushed: the listener keeps the cache warm, so this is a
     cheap read of local state rather than a round trip to the Pi. --}}
<div class="pane-scroll h-full min-h-0" @if ($this->tiles->isNotEmpty()) wire:poll.3s="$refresh" @endif>

    @if ($this->problem)
        <p class="mb-3 rounded-xl bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
            {{ $this->problem }}
        </p>
    @endif

    @if (! $this->configured)
        <div class="grid h-full place-items-center">
            <div class="max-w-sm text-center">
                <p class="text-xl font-semibold text-slate-400">Home Assistant is not connected</p>
                <p class="mt-1 text-sm text-slate-400 dark:text-slate-600">
                    Set HA_URL and HA_TOKEN, then choose what belongs on the wall in Settings → Home.
                </p>
            </div>
        </div>
    @elseif ($this->tiles->isEmpty())
        <div class="grid h-full place-items-center">
            <div class="max-w-sm text-center">
                <p class="text-xl font-semibold text-slate-400">Nothing on the wall yet</p>
                <p class="mt-1 text-sm text-slate-400 dark:text-slate-600">
                    Pick the lights, switches and scenes worth a tile in Settings → Home.
                </p>
            </div>
        </div>
    @else
        @foreach ($this->sections as $section => $tiles)
            <section class="mb-5" wire:key="section-{{ $section }}">
                <h2 class="px-1 pb-2 text-sm font-semibold tracking-wide text-slate-400 uppercase">
                    {{ \App\Models\HomeTile::SECTIONS[$section] }}
                </h2>

                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach ($tiles as $tile)
                        @php
                            $state = $this->stateFor($tile);
                            $on = $state?->isOn() ?? false;
                            $missing = $state === null || $state->isUnavailable();
                        @endphp

                        <div wire:key="tile-{{ $tile->id }}"
                             class="flex flex-col gap-2 rounded-2xl border-2 p-3 transition-colors
                                    {{ $missing
                                        ? 'border-transparent bg-slate-100 dark:bg-slate-800/60'
                                        : ($on ? 'border-transparent bg-amber-100 dark:bg-amber-500/20' : 'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900') }}">

                            {{-- The whole tile is the switch for the things that
                                 have one. Covers and thermostats get buttons
                                 instead, because "toggle" is not what anyone
                                 means by a blind. --}}
                            @if (in_array($tile->domain, ['light', 'switch', 'scene', 'script'], true))
                                <button type="button" wire:click="press({{ $tile->id }})"
                                        class="flex min-h-16 w-full items-center gap-3 text-left"
                                        @disabled($missing)>
                                    <x-ha-icon :domain="$tile->domain" class="text-2xl leading-none" />
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate font-semibold">{{ $tile->title() }}</span>
                                        <span class="block truncate text-sm {{ $missing ? 'text-slate-400' : 'text-slate-500 dark:text-slate-400' }}">
                                            {{ $state?->summary() ?? 'Not responding' }}
                                        </span>
                                        {{-- The room, now that sections are by
                                             kind. Quiet, because it answers a
                                             question that is asked second. --}}
                                        @if ($tile->area)
                                            <span class="block truncate text-xs text-slate-400">{{ $tile->area }}</span>
                                        @endif
                                    </span>
                                </button>
                            @else
                                <div class="flex items-center gap-3">
                                    <x-ha-icon :domain="$tile->domain" class="text-2xl leading-none" />
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate font-semibold">{{ $tile->title() }}</span>
                                        <span class="block truncate text-sm text-slate-500 dark:text-slate-400">
                                            {{ $state?->summary() ?? 'Not responding' }}
                                        </span>
                                        @if ($tile->area)
                                            <span class="block truncate text-xs text-slate-400">{{ $tile->area }}</span>
                                        @endif
                                    </span>
                                </div>

                                @if ($tile->domain === 'cover')
                                    <div class="grid grid-cols-3 gap-1">
                                        @foreach (['open' => 'Open', 'stop' => 'Stop', 'close' => 'Close'] as $action => $label)
                                            <button type="button" wire:click="move({{ $tile->id }}, '{{ $action }}')"
                                                    class="touch-target rounded-xl bg-slate-100 text-sm font-semibold dark:bg-slate-800"
                                                    @disabled($missing)>{{ $label }}</button>
                                        @endforeach
                                    </div>
                                @else
                                    <div class="flex items-center gap-2">
                                        <button type="button" wire:click="nudge({{ $tile->id }}, -0.5)"
                                                class="grid size-11 shrink-0 place-items-center rounded-xl bg-slate-100 text-xl font-bold dark:bg-slate-800"
                                                aria-label="Cooler" @disabled($missing)>−</button>
                                        <span class="flex-1 text-center text-lg font-bold tabular-nums">
                                            {{ $state?->targetTemperature() !== null ? rtrim(rtrim(number_format($state->targetTemperature(), 1, '.', ''), '0'), '.').'°' : '—' }}
                                        </span>
                                        <button type="button" wire:click="nudge({{ $tile->id }}, 0.5)"
                                                class="grid size-11 shrink-0 place-items-center rounded-xl bg-slate-100 text-xl font-bold dark:bg-slate-800"
                                                aria-label="Warmer" @disabled($missing)>+</button>
                                    </div>
                                @endif
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    @endif
</div>
