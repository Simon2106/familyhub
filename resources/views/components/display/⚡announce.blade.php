<?php

use App\Models\Household;
use App\Models\Member;
use App\Services\Announce\Announcer;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Shouting up the stairs, without the stairs.
 *
 * Four presets, because the things a household shouts are the same four
 * things, and typing on a wall is a thing nobody wants to do twice. Where
 * an Alexa is set up in /admin it comes out of the speakers; where it is
 * not, the banner is the whole of it and /admin says so.
 */
new class extends Component
{
    public bool $picking = false;

    /** The last thing said, while the banner is up. */
    public ?array $banner = null;

    public ?string $problem = null;

    protected function household(): Household
    {
        return Household::current();
    }

    #[Computed]
    public function presets(): array
    {
        return $this->household()->announcePresets();
    }

    #[Computed]
    public function speaks(): bool
    {
        return app(Announcer::class)->speaks($this->household());
    }

    public function open(): void
    {
        $this->picking = true;
        $this->problem = null;
    }

    public function close(): void
    {
        $this->picking = false;
    }

    public function dismiss(): void
    {
        $this->banner = null;
    }

    public function say(string $message): void
    {
        $result = app(Announcer::class)->announce($this->household(), $message, $this->member());

        $this->picking = false;

        if (! $result['sent']) {
            $this->problem = $result['reason'];

            return;
        }

        $this->problem = null;
        $this->banner = $result;
    }

    /**
     * Something said out loud rather than tapped.
     *
     * The spoken path recognises "announce …" before the assistant sees it,
     * the same way a timer is recognised, so the read-only promise holds.
     */
    #[On('announce')]
    public function fromSpeech(string $message): void
    {
        $this->say($message);
    }

    /** Whoever is signed in at the wall with a PIN, if anybody. */
    protected function member(): ?Member
    {
        $id = session('wall_member_id');

        return $id ? Member::where('household_id', $this->household()->id)->find($id) : null;
    }
}; ?>

<div class="contents">
    {{-- The button. On the Home face, beside the clock. --}}
    <button type="button" wire:click="open"
            class="press grid touch-target shrink-0 place-items-center rounded-full px-5"
            aria-label="Announce something to the house">
        <span class="flex items-center gap-2">
            <x-icon name="megaphone" class="size-7" />
            <span class="t-body font-medium">Announce</span>
        </span>
    </button>

    @if ($picking)
        <x-modal close-on="$wire.close()" label="Announce" width="max-w-3xl">
            <div class="p-6">
                <h2 class="t-title">Say it to the house</h2>

                <div class="mt-5 grid gap-3 sm:grid-cols-2">
                    @foreach ($this->presets as $preset)
                        <button type="button" wire:click="say(@js($preset))"
                                class="press touch-target rounded-[var(--radius-tile)] bg-slate-100 px-6 py-5 text-left t-body font-medium dark:bg-slate-800">
                            {{ $preset }}
                        </button>
                    @endforeach
                </div>

                <p class="mt-5 t-small opacity-70">
                    @if ($this->speaks)
                        Said out loud on the speakers, and shown here.
                    @else
                        Shown on the wall. No Alexa is set up, so nothing will be said out loud —
                        choose one in Settings &rarr; Home Assistant.
                    @endif
                    You can also ask the wall &mdash; &ldquo;announce dinner&rsquo;s ready&rdquo;.
                </p>

                <button type="button" wire:click="close"
                        class="press touch-target mt-5 w-full rounded-full bg-slate-100 py-4 t-body font-semibold dark:bg-slate-800">
                    Not now
                </button>
            </div>
        </x-modal>
    @endif

    @if ($problem)
        <div class="fixed inset-x-0 top-0 z-[85] bg-amber-400 px-10 py-4 text-slate-900" role="status">
            <p class="t-body font-medium">{{ $problem }}</p>
        </div>
    @endif

    {{-- The banner. Twenty seconds, then it takes itself away. --}}
    @if ($banner)
        <div class="fixed inset-x-0 top-0 z-[85] flex items-center gap-6 bg-blue-600 px-10 py-7 text-white"
             role="status"
             x-data="{ }"
             x-init="setTimeout(() => $wire.dismiss(), {{ \App\Services\Announce\Announcer::BANNER_SECONDS * 1000 }})">
            <x-icon name="megaphone" class="size-12 shrink-0" />
            <p class="min-w-0 flex-1 truncate t-face">{{ $banner['message'] }}</p>
            <p class="shrink-0 t-body opacity-80">{{ $banner['by'] }}</p>
            <button type="button" wire:click="dismiss"
                    class="press shrink-0 rounded-full bg-white/20 px-8 py-3 t-body font-semibold">
                OK
            </button>
        </div>
    @endif
</div>
