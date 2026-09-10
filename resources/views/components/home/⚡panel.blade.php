<?php

use App\Exceptions\HomeAssistantException;
use App\Models\Household;
use App\Models\HomeTile;
use App\Services\HomeAssistant\EntityState;
use App\Services\HomeAssistant\HomeAssistant;
use App\Models\SwitchGroup;
use App\Services\HomeAssistant\MediaPlayer;
use App\Services\HomeAssistant\SwitchBoard;
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
        $this->forgetHome();
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

        $this->forgetHome();
    }

    /** Which group's members are open, from a long press on the wall. */
    public ?int $openGroupId = null;

    /** A group whose on/off delays are being edited in place. */
    public ?int $timingGroupId = null;

    public int $onDelay = 0;

    public int $offDelay = 0;

    /** @return Collection<int, SwitchGroup> */
    #[Computed]
    public function groups(): Collection
    {
        return $this->configured ? app(SwitchBoard::class)->groups($this->household()) : collect();
    }

    /** on | off | mixed | unknown, per group. */
    #[Computed]
    public function groupStates(): array
    {
        $states = $this->reading['states'];

        return $this->groups
            ->mapWithKeys(fn (SwitchGroup $group) => [
                $group->id => app(SwitchBoard::class)->stateOf($group, $states),
            ])
            ->all();
    }

    public function pressGroup(int $id): void
    {
        $this->error = null;

        try {
            app(SwitchBoard::class)->press($this->findGroup($id), $this->reading['states']);
        } catch (HomeAssistantException $e) {
            $this->error = $e->getMessage();
        }

        $this->forgetHome();
    }

    public function cancelGroup(int $id): void
    {
        app(SwitchBoard::class)->cancel($this->findGroup($id));

        $this->forgetHome();
    }

    /** The long press: show what is in the group so one can be tapped alone. */
    public function openGroup(int $id): void
    {
        $this->openGroupId = $this->openGroupId === $id ? null : $id;
        $this->timingGroupId = null;
    }

    public function toggleMember(int $id, string $entityId): void
    {
        $this->error = null;

        try {
            app(SwitchBoard::class)->toggleMember($this->findGroup($id), $entityId);
        } catch (HomeAssistantException $e) {
            $this->error = $e->getMessage();
        }

        $this->forgetHome();
    }

    /** Editing the two delays from the phone, without going to /admin. */
    public function editTiming(int $id): void
    {
        $group = $this->findGroup($id);

        $this->timingGroupId = $this->timingGroupId === $id ? null : $id;
        $this->onDelay = $group->on_delay;
        $this->offDelay = $group->off_delay;
        $this->openGroupId = null;
    }

    public function saveTiming(): void
    {
        if (! $this->timingGroupId) {
            return;
        }

        $this->findGroup($this->timingGroupId)->update([
            'on_delay' => max(0, min(SwitchGroup::MAX_DELAY, $this->onDelay)),
            'off_delay' => max(0, min(SwitchGroup::MAX_DELAY, $this->offDelay)),
        ]);

        $this->timingGroupId = null;
        $this->forgetHome();

        $this->dispatch('saved', message: 'Timings saved.');
    }

    protected function findGroup(int $id): SwitchGroup
    {
        return SwitchGroup::where('household_id', $this->household()->id)
            ->with('entities')
            ->findOrFail($id);
    }

    protected function forgetHome(): void
    {
        unset($this->reading, $this->states, $this->problem, $this->sections,
            $this->nowPlaying, $this->groups, $this->groupStates);
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
                // Groups count as a reason to read: a household with groups
                // and no individual tiles was getting no reading at all, so
                // every group tile said "Not responding".
                'states' => $this->tiles->isEmpty() && $this->groups->isEmpty()
                    ? collect()
                    : $home->states(),
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

        $this->forgetHome();
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

        $this->forgetHome();
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

    {{-- Groups first: a household reaches for "the lamps" far more often than
         for any one of them, and the tile that turns a room off should be the
         one nearest the top. --}}
    @if ($this->groups->isNotEmpty())
        <section class="mb-5">
            <h2 class="px-1 pb-2 text-sm font-semibold tracking-wide text-slate-400 uppercase">Groups</h2>

            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($this->groups as $group)
                    @php
                        $state = $this->groupStates[$group->id] ?? 'unknown';
                        $left = $group->secondsLeft();
                        $waiting = $group->hasPending();
                    @endphp

                    <div wire:key="group-{{ $group->id }}"
                         class="flex flex-col gap-2 rounded-2xl border-2 p-3 transition-colors
                                {{ $waiting
                                    ? 'border-blue-500 bg-blue-50 dark:bg-blue-950/40'
                                    : ($state === 'on'
                                        ? 'border-transparent bg-amber-100 dark:bg-amber-500/20'
                                        : ($state === 'unknown'
                                            ? 'border-transparent bg-slate-100 dark:bg-slate-800/60'
                                            : 'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900')) }}">

                        {{-- Press and hold to see inside, the same gesture as
                             lifting a meal off the planner. --}}
                        {{-- One gesture, one action. There is deliberately no
                             wire:click here: with both, a long press fired the
                             tap as well — Livewire's handler is not something
                             stopImmediatePropagation can reach — so the group
                             opened *and* started a countdown. Alpine decides
                             which it was and calls the one. --}}
                        <button type="button"
                                x-data="{ held: false, spent: false, timer: null }"
                                x-on:pointerdown="held = false; spent = false;
                                    timer = setTimeout(() => { held = true; $wire.openGroup({{ $group->id }}) }, 550)"
                                x-on:pointerup="clearTimeout(timer);
                                    if (! held && ! spent) { spent = true; $wire.pressGroup({{ $group->id }}) }"
                                x-on:pointercancel="clearTimeout(timer); spent = true"
                                x-on:pointerleave="clearTimeout(timer); spent = true"
                                class="flex min-h-16 w-full items-center gap-3 text-left"
                                @disabled($state === 'unknown' && ! $waiting)>
                            <x-icon name="switch" class="size-6 shrink-0 {{ $state === 'on' ? 'text-amber-600 dark:text-amber-400' : 'text-slate-400' }}" />

                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-semibold">{{ $group->name }}</span>
                                <span class="block truncate text-sm {{ $waiting ? 'font-semibold text-blue-700 dark:text-blue-300' : 'text-slate-500 dark:text-slate-400' }}">
                                    @if ($waiting)
                                        {{ ucfirst($group->pending_direction) }} in
                                        <span x-data="{ left: {{ $left }} }"
                                              x-init="setInterval(() => left > 0 && left--, 1000)"
                                              x-text="`${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`"
                                              class="tabular-nums">{{ sprintf('%d:%02d', intdiv($left, 60), $left % 60) }}</span>
                                    @elseif ($state === 'unknown')
                                        Not responding
                                    @elseif ($state === 'mixed')
                                        {{ $group->entities->count() }} switches · some on
                                    @else
                                        {{ ucfirst($state) }} · {{ $group->entities->count() }} {{ Str::plural('switch', $group->entities->count()) }}
                                    @endif
                                </span>
                            </span>
                        </button>

                        @if ($waiting)
                            <button type="button" wire:click="cancelGroup({{ $group->id }})"
                                    class="touch-target rounded-xl bg-white text-sm font-semibold text-blue-700 dark:bg-slate-900 dark:text-blue-300">
                                Cancel
                            </button>
                        @elseif (! $group->isInstant('on') || ! $group->isInstant('off'))
                            <p class="px-1 text-xs text-slate-400">
                                @if (! $group->isInstant('on')) On after {{ $group->on_delay }} min @endif
                                @if (! $group->isInstant('on') && ! $group->isInstant('off')) · @endif
                                @if (! $group->isInstant('off')) Off after {{ $group->off_delay }} min @endif
                            </p>
                        @endif

                        {{-- What is in it, from a long press. --}}
                        @if ($openGroupId === $group->id)
                            <ul class="space-y-1 border-t border-slate-200/70 pt-2 dark:border-slate-700">
                                @foreach ($group->entities as $member)
                                    @php $memberState = $this->states->get($member->entity_id); @endphp

                                    <li wire:key="member-{{ $member->id }}">
                                        <button type="button"
                                                wire:click="toggleMember({{ $group->id }}, '{{ $member->entity_id }}')"
                                                class="flex w-full touch-target items-center gap-2 rounded-xl px-2 text-left">
                                            <span class="size-2.5 shrink-0 rounded-full {{ $memberState?->isOn() ? 'bg-amber-500' : 'bg-slate-300 dark:bg-slate-600' }}"></span>
                                            <span class="min-w-0 flex-1 truncate text-sm">{{ $member->title() }}</span>
                                            <span class="shrink-0 text-xs text-slate-400">{{ $memberState?->summary() ?? 'Not responding' }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        {{-- Editable here as well as in /admin: the timing is
                             the thing most often got wrong by a minute or two,
                             and a phone is where somebody notices. --}}
                        @if ($timingGroupId === $group->id)
                            <div class="space-y-2 border-t border-slate-200/70 pt-2 dark:border-slate-700">
                                @foreach (['onDelay' => 'On after', 'offDelay' => 'Off after'] as $field => $label)
                                    <label class="flex items-center gap-2">
                                        <span class="w-20 shrink-0 text-sm">{{ $label }}</span>
                                        <input wire:model="{{ $field }}" type="number" min="0" max="{{ \App\Models\SwitchGroup::MAX_DELAY }}"
                                               class="w-20 rounded-xl border border-slate-300 px-2 py-1 text-base dark:border-slate-600 dark:bg-slate-950">
                                        <span class="text-sm text-slate-400">min · 0 is instant</span>
                                    </label>
                                @endforeach

                                <button type="button" wire:click="saveTiming"
                                        class="touch-target w-full rounded-xl bg-blue-600 text-sm font-semibold text-white">Save</button>
                            </div>
                        @else
                            <button type="button" wire:click="editTiming({{ $group->id }})"
                                    class="px-1 text-left text-xs font-semibold text-slate-400">Timings</button>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if (! $this->configured)
        <div class="grid h-full place-items-center">
            <div class="max-w-sm text-center">
                <p class="text-xl font-semibold text-slate-400">Home Assistant is not connected</p>
                <p class="mt-1 text-sm text-slate-400 dark:text-slate-400">
                    Set HA_URL and HA_TOKEN, then choose what belongs on the wall in Settings → Home.
                </p>
            </div>
        </div>
    @elseif ($this->tiles->isEmpty())
        <div class="grid h-full place-items-center">
            <div class="max-w-sm text-center">
                <p class="text-xl font-semibold text-slate-400">Nothing on the wall yet</p>
                <p class="mt-1 text-sm text-slate-400 dark:text-slate-400">
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
