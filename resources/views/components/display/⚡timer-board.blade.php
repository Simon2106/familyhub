<?php

use App\Models\Household;
use App\Models\Timer;
use App\Services\Timers\TimerBoard;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The Timers tab.
 *
 * The strip along the top is for glancing at; this is for setting one up
 * with a name, and for seeing the ones that have been dismissed today when
 * somebody asks what happened to the bread.
 */
new class extends Component
{
    public string $name = '';

    public int $minutes = 10;

    protected function board(): TimerBoard
    {
        return app(TimerBoard::class);
    }

    protected function household(): Household
    {
        return Household::current();
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function running(): array
    {
        return $this->board()->forScreen($this->household());
    }

    /** What has been and gone today, newest first. */
    #[Computed]
    public function finished(): \Illuminate\Support\Collection
    {
        return Timer::query()
            ->forHousehold($this->household())
            ->whereNotNull('dismissed_at')
            ->orderByDesc('dismissed_at')
            ->limit(8)
            ->get();
    }

    #[Computed]
    public function presets(): array
    {
        return [1, 2, 3, 5, 10, 15, 20, 25, 30, 45, 60, 90];
    }

    public function start(): void
    {
        $this->validate([
            'name' => 'nullable|string|max:60',
            'minutes' => 'required|integer|min:1|max:1440',
        ]);

        $this->board()->start($this->household(), $this->minutes * 60, $this->name, 'manual');

        $this->name = '';

        $this->changed();
    }

    public function quick(int $minutes): void
    {
        $this->minutes = max(1, min($minutes, 1440));
    }

    public function dismiss(int $id): void
    {
        Timer::query()->forHousehold($this->household())->find($id)?->dismiss();

        $this->changed();
    }

    #[On('timers-changed')]
    public function refresh(): void
    {
        unset($this->running, $this->finished);
    }

    protected function changed(): void
    {
        unset($this->running, $this->finished);

        $this->dispatch('timers-changed');
    }
}; ?>

<div class="pane-scroll h-full min-h-0 px-2">
    <div class="mx-auto grid max-w-6xl gap-6 pb-10 xl:grid-cols-2">
        {{-- Setting one. --}}
        <section>
            <h2 class="text-[1.0625rem] font-medium uppercase tracking-[0.06em] opacity-70">New timer</h2>

            <label class="mt-3 block">
                <span class="sr-only">What the timer is for</span>
                <input wire:model="name" type="text" placeholder="What for — pasta, laundry, homework"
                       class="touch-target w-full rounded-2xl border border-slate-300 px-5 text-[1.375rem] dark:border-slate-700 dark:bg-slate-950">
            </label>
            @error('name') <p class="mt-1 text-[1.0625rem] text-red-600">{{ $message }}</p> @enderror

            <div class="mt-4 grid grid-cols-4 gap-2">
                @foreach ($this->presets as $preset)
                    <button type="button" wire:click="quick({{ $preset }})"
                            class="touch-target rounded-2xl py-3 text-[1.375rem] font-medium tabular-nums {{ $minutes === $preset ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-800' }}">
                        {{ $preset }}
                    </button>
                @endforeach
            </div>

            <p class="mt-2 text-[1.0625rem] opacity-70">minutes</p>
            @error('minutes') <p class="mt-1 text-[1.0625rem] text-red-600">{{ $message }}</p> @enderror

            <button type="button" wire:click="start"
                    class="touch-target mt-4 w-full rounded-2xl bg-blue-600 py-5 text-[1.75rem] font-semibold text-white">
                Start {{ $minutes }} min
            </button>

            <p class="mt-3 text-[1.0625rem] opacity-70">
                You can also ask the wall — &ldquo;set a 20 minute pasta timer&rdquo;.
            </p>
        </section>

        {{-- What is going. --}}
        <section>
            <h2 class="text-[1.0625rem] font-medium uppercase tracking-[0.06em] opacity-70">Running</h2>

            @if ($this->running === [])
                <p class="mt-3 text-[1.375rem] opacity-70">Nothing running.</p>
            @else
                <ul class="mt-3 space-y-2"
                    x-data="{ now: Date.now(), init() { this.t = setInterval(() => this.now = Date.now(), 500) }, destroy() { clearInterval(this.t) } }">
                    @foreach ($this->running as $timer)
                        <li wire:key="run-{{ $timer['id'] }}"
                            class="flex items-center gap-4 rounded-[1.5rem] bg-slate-100 px-5 py-4 dark:bg-slate-800">
                            <span class="min-w-0 flex-1 truncate text-[1.75rem] font-semibold">{{ $timer['name'] }}</span>

                            <span class="display-digits shrink-0 text-[2.5rem] font-semibold tabular-nums"
                                  x-data="{ ends: @js($timer['endsAt']), paused: @js($timer['paused']), left: @js($timer['remaining']) }"
                                  x-text="paused || ! ends ? window.familyhubTimers.formatted(left) : window.familyhubTimers.formatted(Math.max(0, Math.ceil((ends - now) / 1000)))"></span>

                            <button type="button" wire:click="dismiss({{ $timer['id'] }})"
                                    class="touch-target shrink-0 rounded-full bg-white px-6 text-[1.0625rem] font-medium dark:bg-slate-900">
                                Stop
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($this->finished->isNotEmpty())
                <h2 class="mt-8 text-[1.0625rem] font-medium uppercase tracking-[0.06em] opacity-70">Earlier</h2>
                <ul class="mt-3 space-y-1">
                    @foreach ($this->finished as $timer)
                        <li wire:key="done-{{ $timer->id }}" class="flex items-baseline gap-3 px-2 text-[1.375rem] opacity-70">
                            <span class="min-w-0 flex-1 truncate">{{ $timer->label() }}</span>
                            <span class="shrink-0 tabular-nums">{{ intdiv($timer->seconds, 60) }} min</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</div>
