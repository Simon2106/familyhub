<?php

use App\Models\Household;
use App\Services\Activity\ActivityFeed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * What the house has been doing.
 *
 * A strip when it is on the Home face — one line, the latest thing, how long
 * ago — and the last day grouped by hour when somebody taps it. Nothing at
 * all when no entities have been chosen in /admin, which is the state every
 * household starts in.
 */
new class extends Component
{
    /** The strip shows one line; the tab shows the day. */
    public bool $full = false;

    protected function household(): Household
    {
        return Household::current();
    }

    protected function feed(): ActivityFeed
    {
        return app(ActivityFeed::class);
    }

    #[Computed]
    public function isOn(): bool
    {
        return $this->household()->activityEntities() !== [];
    }

    #[Computed]
    public function latest(): ?array
    {
        return $this->isOn ? $this->feed()->latest($this->household()) : null;
    }

    /** @return Collection<string, Collection<int, array<string, mixed>>> */
    #[Computed]
    public function byHour(): Collection
    {
        return $this->isOn ? $this->feed()->byHour($this->household()) : collect();
    }

    /** "4 min ago" — relative, because that is the only form anybody reads. */
    public function ago(CarbonImmutable $at): string
    {
        return $at->diffForHumans(['parts' => 1, 'short' => false]);
    }

    public function when(CarbonImmutable $at): string
    {
        return $at->timezone($this->household()->displayTimezone())->format('H:i');
    }

    #[On('home-state-changed')]
    #[On('activity-changed')]
    public function refresh(): void
    {
        unset($this->latest, $this->byHour, $this->isOn);
    }
}; ?>

<div class="contents">
    @if (! $this->isOn)
        @if ($full)
            <div class="grid h-full place-items-center">
                <p class="t-body max-w-xl text-center opacity-70">
                    Nothing is being watched yet. Choose which doors, lights and people the
                    house should keep you posted about in Settings &rarr; Home Assistant,
                    on a phone.
                </p>
            </div>
        @endif
    @elseif ($full)
        {{-- The last day, by the hour. --}}
        <div class="pane-scroll h-full min-h-0 px-2">
            <div class="mx-auto max-w-5xl pb-10">
                @forelse ($this->byHour as $hour => $clusters)
                    <section class="mb-6" wire:key="hour-{{ $hour }}">
                        <h2 class="t-label sticky top-0 py-2">{{ $hour }}</h2>

                        <div class="space-y-2">
                            @foreach ($clusters as $cluster)
                                <div class="tile flex items-baseline gap-4 px-5 py-3"
                                     wire:key="cl-{{ $hour }}-{{ $loop->index }}">
                                    <span class="w-16 shrink-0 t-body t-num opacity-60">{{ $this->when($cluster['at']) }}</span>
                                    <span class="min-w-0 flex-1 t-body">{{ $cluster['sentence'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @empty
                    <p class="t-body py-10 text-center opacity-70">Nothing in the last day.</p>
                @endforelse
            </div>
        </div>
    @elseif ($this->latest)
        {{-- The strip. One line, tappable, and gone when there is nothing. --}}
        <button type="button" x-on:click="tab = 'activity'"
                class="press glass flex w-full items-center gap-4 px-6 py-3 text-left"
                aria-label="What the house has been doing">
            <span class="size-2.5 shrink-0 rounded-full bg-emerald-500" aria-hidden="true"></span>
            <span class="min-w-0 flex-1 truncate t-body">{{ $this->latest['sentence'] }}</span>
            <span class="shrink-0 t-small opacity-60">{{ $this->ago($this->latest['at']) }}</span>
        </button>
    @endif
</div>
