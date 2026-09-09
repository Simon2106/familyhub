<?php

use App\Exceptions\HomeAssistantException;
use App\Models\Household;
use App\Models\HomeTile;
use App\Services\HomeAssistant\EntityState;
use App\Services\HomeAssistant\HomeAssistant;
use App\Services\HomeAssistant\MediaPlayer;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
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

    /**
     * What we have asked a tile to become, and when we asked.
     *
     * A tap flips the tile immediately in the browser, but the browser cannot
     * hold that opinion for long without either lying or flickering. So the
     * expectation lives here instead: every render until it comes true shows
     * the state we asked for, and if Home Assistant has not agreed within a
     * few seconds the tile goes back to the truth and says so.
     *
     * @var array<int, array{state: bool, at: int}>
     */
    public array $expecting = [];

    /** @var array<int, array{text: string, at: int}> */
    public array $notes = [];

    /** How long a light gets to do as it is told before we stop believing. */
    public const SETTLE_SECONDS = 5;

    /** How long "didn't respond" stays on screen. */
    public const NOTE_SECONDS = 8;

    public function household(): Household
    {
        return Household::current();
    }

    #[Computed]
    public function configured(): bool
    {
        return app(HomeAssistant::class)->isConfigured();
    }

    /**
     * Home Assistant changed something. Look again.
     *
     * The event carries nothing; everything the wall shows comes from a local
     * cache the listener has already updated, so this is a cheap re-read
     * rather than a round trip to the Pi.
     */
    #[On('echo:'.\App\Events\HomeStateChanged::CHANNEL.',.state-changed')]
    public function refreshFromHome(): void
    {
        unset($this->reading, $this->states, $this->problem, $this->sections, $this->nowPlaying);
    }

    /**
     * How often to poll when nothing is pushing.
     *
     * With broadcasting on, the poll is only a safety net for a dropped
     * socket, so it can be lazy. Without it, the poll is the only thing
     * keeping the tiles honest and has to be brisk.
     */
    #[Computed]
    public function pollInterval(): string
    {
        return config('broadcasting.default') === 'reverb' ? '10s' : '3s';
    }

    /**
     * Whatever is playing in the house right now.
     *
     * Found rather than configured: nobody should have to add the kitchen
     * speaker to the wall before the wall will admit music is coming out of
     * it. Silence costs nothing — an idle or off player is not shown at all,
     * so the card only exists on the days it has something to say.
     *
     * @return Collection<int, MediaPlayer>
     */
    #[Computed]
    public function nowPlaying(): Collection
    {
        return $this->reading['media'];
    }

    /** Play, pause, skip or change the volume of something already playing. */
    public function control(string $entityId, string $action): void
    {
        $this->error = null;

        try {
            app(HomeAssistant::class)->media($entityId, $action);
        } catch (HomeAssistantException $e) {
            $this->error = $e->getMessage();
        }

        unset($this->reading, $this->states, $this->problem, $this->sections, $this->nowPlaying);
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
     * One instance, not two calls to app(): the tiles and whatever is playing
     * come out of the same reading, so a render is one request to the Pi
     * however short the shared cache is set.
     *
     * @return array{states: Collection<string, EntityState>, media: Collection<int, MediaPlayer>, error: ?string}
     */
    #[Computed]
    public function reading(): array
    {
        $nothing = ['states' => collect(), 'media' => collect(), 'error' => null];

        if (! $this->configured) {
            return $nothing;
        }

        try {
            $home = app(HomeAssistant::class);

            return [
                'states' => $this->tiles->isEmpty() ? collect() : $home->states(),
                'media' => $home->mediaPlayers()
                    ->filter(fn (MediaPlayer $player) => $player->isActive())
                    ->values(),
                'error' => null,
            ];
        } catch (HomeAssistantException $e) {
            return ['states' => collect(), 'media' => collect(), 'error' => $e->getMessage()];
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
     * Compare what we asked for against what Home Assistant now says.
     *
     * A lifecycle hook rather than something the template calls: hanging this
     * off the outermost loop meant it silently stopped running whenever the
     * template took a different branch — removing the last tile left an
     * expectation behind forever. booted() runs on every request whatever the
     * page decides to draw.
     */
    public function booted(): void
    {
        $this->reconcile();
    }

    protected function reconcile(): void
    {
        $now = now()->timestamp;

        foreach ($this->expecting as $tileId => $expectation) {
            $tile = $this->tiles->firstWhere('id', $tileId);
            $actual = $tile ? $this->states->get($tile->entity_id) : null;

            if (! $tile) {
                unset($this->expecting[$tileId]);

                continue;
            }

            // It did as it was told. Nothing more to watch for.
            if ($actual && ! $actual->isUnavailable() && $actual->isOn() === $expectation['state']) {
                unset($this->expecting[$tileId]);

                continue;
            }

            if ($now - $expectation['at'] >= self::SETTLE_SECONDS) {
                unset($this->expecting[$tileId]);

                // Reverting silently would look like the tap missed. Saying so
                // is the difference between a bug and a flat battery.
                $this->notes[$tileId] = ['text' => 'Didn\'t respond', 'at' => $now];
            }
        }

        foreach ($this->notes as $tileId => $note) {
            if ($now - $note['at'] >= self::NOTE_SECONDS) {
                unset($this->notes[$tileId]);
            }
        }
    }

    /** What the tile should show: what we asked for while we still believe it. */
    public function showsOn(HomeTile $tile): bool
    {
        if (isset($this->expecting[$tile->id])) {
            return $this->expecting[$tile->id]['state'];
        }

        return $this->stateFor($tile)?->isOn() ?? false;
    }

    public function isPending(HomeTile $tile): bool
    {
        return isset($this->expecting[$tile->id]);
    }

    public function noteFor(HomeTile $tile): ?string
    {
        return $this->notes[$tile->id]['text'] ?? null;
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
        $tile = $this->tiles->firstWhere('id', $tileId);

        if (! $tile) {
            return;
        }

        // Recorded before the call, not after: a Pi taking four seconds to
        // answer is exactly when the tile most needs to look like it heard.
        if ($this->isSwitchable($tile)) {
            $this->expect($tile, ! $this->showsOn($tile));
        }

        $this->act($tileId, fn (HomeAssistant $ha, HomeTile $t) => $ha->toggle($t->entity_id));

        // Lets the browser stop holding its own opinion: from here the server
        // is carrying the optimism, and it knows when to give up on it.
        $this->dispatch('tile-settled', tile: $tileId);
    }

    public function isSwitchableTile(HomeTile $tile): bool
    {
        return $this->isSwitchable($tile);
    }

    /** Scenes and scripts have no state to be optimistic about. */
    protected function isSwitchable(HomeTile $tile): bool
    {
        return in_array($tile->domain, ['light', 'switch', 'cover', 'climate'], true);
    }

    protected function expect(HomeTile $tile, bool $state): void
    {
        unset($this->notes[$tile->id]);

        $this->expecting[$tile->id] = ['state' => $state, 'at' => now()->timestamp];
    }

    public function move(int $tileId, string $action): void
    {
        $tile = $this->tiles->firstWhere('id', $tileId);

        if ($tile && $action !== 'stop') {
            $this->expect($tile, $action === 'open');
        }

        $this->act($tileId, fn (HomeAssistant $ha, HomeTile $t) => $ha->cover($t->entity_id, $action));

        $this->dispatch('tile-settled', tile: $tileId);
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

        unset($this->reading, $this->states, $this->problem, $this->sections, $this->nowPlaying);
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

            // A call that never left the box cannot come true, so stop
            // pretending it might.
            unset($this->expecting[$tile->id]);
        }

        unset($this->reading, $this->states, $this->problem, $this->sections, $this->nowPlaying);
    }
}; ?>

{{-- Pushed when Reverb is running, polled when it is not — and polled anyway,
     slowly, so a dropped socket costs a few seconds rather than the tab. Either
     way this reads the local cache the listener keeps warm, not the Pi. --}}
<div class="pane-scroll h-full min-h-0"
     @if ($this->configured) wire:poll.{{ $this->pollInterval }}="$refresh" @endif>

    @if ($this->problem)
        <p class="mb-3 rounded-xl bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
            {{ $this->problem }}
        </p>
    @endif

    {{-- Whatever is playing, above the switches: it is the thing on this tab
         most likely to be wanted in a hurry, and it is the only thing on it
         that changes by itself. --}}
    @foreach ($this->nowPlaying as $player)
        @php $art = $player->artwork(); @endphp

        <div wire:key="playing-{{ $player->entityId }}"
             class="mb-3 flex items-center gap-3 rounded-2xl bg-slate-900 p-3 text-white dark:bg-slate-800">
            @if ($art)
                {{-- Decoration: everything it shows is in the text as well, so
                     a phone that cannot reach Home Assistant loses nothing. --}}
                <img src="{{ $art }}" alt=""
                     class="size-14 shrink-0 rounded-xl object-cover"
                     onerror="this.remove()">
            @else
                <span class="grid size-14 shrink-0 place-items-center rounded-xl bg-white/10">
                    <x-icon name="music" class="size-7 text-white/70" />
                </span>
            @endif

            <span class="min-w-0 flex-1">
                {{-- Larger than a tile's label: this is read from across a
                     kitchen, not tapped from in front of it. --}}
                <span class="block truncate text-lg font-semibold">{{ $player->title() ?? $player->name() }}</span>
                @if ($player->subtitle())
                    <span class="block truncate text-white/70">{{ $player->subtitle() }}</span>
                @endif
                {{-- Which speaker, unless the speaker is all we could name it
                     by — "Playing on Bathroom radio" under the heading
                     "Bathroom radio" says nothing twice. --}}
                <span class="block truncate text-xs text-white/50">
                    {{ $player->title() === null
                        ? ($player->isPlaying() ? 'Playing' : 'Paused')
                        : ($player->isPlaying() ? 'Playing on ' : 'Paused on ').$player->name() }}
                </span>
            </span>

            <span class="flex shrink-0 items-center gap-1">
                @if ($player->can(\App\Services\HomeAssistant\MediaPlayer::PREVIOUS))
                    <button type="button" wire:click="control('{{ $player->entityId }}', 'previous')"
                            class="grid size-11 place-items-center rounded-xl bg-white/10" aria-label="Previous track">
                        <svg class="size-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M7 6h2v12H7zM20 6v12l-9-6z" />
                        </svg>
                    </button>
                @endif

                @if ($player->canPlayPause())
                    <button type="button" wire:click="control('{{ $player->entityId }}', 'play-pause')"
                            class="grid size-12 place-items-center rounded-xl bg-white text-slate-900"
                            aria-label="{{ $player->isPlaying() ? 'Pause' : 'Play' }}">
                        <svg class="size-6" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            @if ($player->isPlaying())
                                <path d="M8 5h3v14H8zM13 5h3v14h-3z" />
                            @else
                                <path d="M8 5v14l11-7z" />
                            @endif
                        </svg>
                    </button>
                @endif

                @if ($player->can(\App\Services\HomeAssistant\MediaPlayer::NEXT))
                    <button type="button" wire:click="control('{{ $player->entityId }}', 'next')"
                            class="grid size-11 place-items-center rounded-xl bg-white/10" aria-label="Next track">
                        <svg class="size-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M15 6h2v12h-2zM4 6v12l9-6z" />
                        </svg>
                    </button>
                @endif

                @if ($player->canChangeVolume())
                    <button type="button" wire:click="control('{{ $player->entityId }}', 'quieter')"
                            class="grid size-11 place-items-center rounded-xl bg-white/10 text-xl font-bold" aria-label="Quieter">&minus;</button>
                    <button type="button" wire:click="control('{{ $player->entityId }}', 'louder')"
                            class="grid size-11 place-items-center rounded-xl bg-white/10 text-xl font-bold" aria-label="Louder">+</button>
                @endif
            </span>
        </div>
    @endforeach

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
                            $on = $this->showsOn($tile);
                            $pending = $this->isPending($tile);
                            $note = $this->noteFor($tile);
                            // A pending tile is not missing, however quiet HA is
                            // being about it — we are mid-conversation.
                            $missing = ! $pending && ($state === null || $state->isUnavailable());
                        @endphp

                        {{-- `wanted` is the browser's own opinion, held only
                             for the length of the round trip. The moment the
                             server answers it takes over, because it is the one
                             that knows when to stop believing. --}}
                        <div wire:key="tile-{{ $tile->id }}"
                             x-data="{
                                 wanted: null,
                                 flip(to) { this.wanted = to },
                             }"
                             x-on:tile-settled.window="if ($event.detail.tile === {{ $tile->id }}) wanted = null"
                             :class="wanted === null
                                 ? '{{ $missing
                                        ? 'border-transparent bg-slate-100 dark:bg-slate-800/60'
                                        : ($on ? 'border-transparent bg-amber-100 dark:bg-amber-500/20' : 'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900') }}'
                                 : (wanted
                                     ? 'border-transparent bg-amber-100 dark:bg-amber-500/20'
                                     : 'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900')"
                             class="flex flex-col gap-2 rounded-2xl border-2 p-3 transition-colors">

                            {{-- The whole tile is the switch for the things that
                                 have one. Covers and thermostats get buttons
                                 instead, because "toggle" is not what anyone
                                 means by a blind. --}}
                            @if (in_array($tile->domain, ['light', 'switch', 'scene', 'script'], true))
                                <button type="button"
                                        x-on:click="flip({{ $this->isSwitchableTile($tile) ? '! '.($on ? 'true' : 'false') : 'null' }})"
                                        wire:click="press({{ $tile->id }})"
                                        class="flex min-h-16 w-full items-center gap-3 text-left"
                                        @disabled($missing)>
                                    <x-ha-icon :domain="$tile->domain" class="text-2xl leading-none" />
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate font-semibold">{{ $tile->title() }}</span>
                                        <span class="block truncate text-sm {{ $missing ? 'text-slate-400' : 'text-slate-500 dark:text-slate-400' }}"
                                              x-show="wanted === null">
                                            {{ $note ?? ($pending ? ($on ? 'On' : 'Off') : ($state?->summary() ?? 'Not responding')) }}
                                        </span>
                                        <span class="block truncate text-sm text-slate-500 dark:text-slate-400"
                                              x-show="wanted !== null" x-cloak x-text="wanted ? 'On' : 'Off'"></span>
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
                                            {{ $note ?? $state?->summary() ?? 'Not responding' }}
                                        </span>
                                        @if ($tile->area)
                                            <span class="block truncate text-xs text-slate-400">{{ $tile->area }}</span>
                                        @endif
                                    </span>
                                </div>

                                @if ($tile->domain === 'cover')
                                    <div class="grid grid-cols-3 gap-1">
                                        @foreach (['open' => 'Open', 'stop' => 'Stop', 'close' => 'Close'] as $action => $label)
                                            <button type="button"
                                                    @if ($action !== 'stop') x-on:click="flip({{ $action === 'open' ? 'true' : 'false' }})" @endif
                                                    wire:click="move({{ $tile->id }}, '{{ $action }}')"
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
