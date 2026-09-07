<?php

use App\Models\Chore;
use App\Models\Household;
use App\Models\Member;
use App\Services\Chores\ChoreBoard;
use App\Services\Points\PointsLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * One child's day, at a size a child can hit.
 *
 * Opened by tapping their avatar on the wall. Ticking a chore here needs no
 * PIN — the whole arrangement falls apart if doing your jobs is gated behind a
 * password. PINs guard spending points and undoing a grown-up's decision, and
 * nothing else.
 */
new class extends Component
{
    public ?int $memberId = null;

    public string $date = '';

    /** Set while a tick is being celebrated, so the animation has something to key on. */
    public ?int $justDone = null;

    #[On('show-my-day')]
    public function show(int $member, ?string $date = null): void
    {
        $this->memberId = $member;
        $this->date = $date ?: Household::current()->todayLocal()->toDateString();
        $this->justDone = null;

        unset($this->member, $this->chores, $this->balance);
    }

    public function close(): void
    {
        $this->reset(['memberId', 'date', 'justDone']);
    }

    #[Computed]
    public function member(): ?Member
    {
        return $this->memberId
            ? Member::where('household_id', Household::current()->id)->find($this->memberId)
            : null;
    }

    #[Computed]
    public function day(): CarbonImmutable
    {
        return $this->date ? CarbonImmutable::parse($this->date) : Household::current()->todayLocal();
    }

    /**
     * Today's chores for this child.
     *
     * Not named `slots`: Livewire owns that name and typed it as an array, so
     * a computed of that name blows up on access — or worse, when the types
     * happen to line up, is silently shadowed by an empty one.
     *
     * @return Collection<int, \App\Services\Chores\ChoreSlot>
     */
    #[Computed]
    public function chores(): Collection
    {
        if (! $this->member) {
            return collect();
        }

        return app(ChoreBoard::class)
            ->forDay(Household::current(), $this->day)
            ->filter(fn ($slot) => $slot->chore->member_id === $this->member->id)
            ->values();
    }

    #[Computed]
    public function balance(): int
    {
        return $this->member ? app(PointsLedger::class)->balanceFor($this->member) : 0;
    }

    #[Computed]
    public function done(): int
    {
        return $this->chores->filter(fn ($slot) => $slot->isDone())->count();
    }

    /** Everything ticked, which is the state worth making a fuss of. */
    #[Computed]
    public function allDone(): bool
    {
        return $this->chores->isNotEmpty() && $this->done === $this->chores->count();
    }

    /** No PIN. Doing your jobs is never gated. */
    public function tick(int $choreId): void
    {
        $chore = $this->choreFor($choreId);
        $board = app(ChoreBoard::class);
        $slot = $this->chores->firstWhere(fn ($s) => $s->chore->id === $choreId);

        if ($slot?->isDone()) {
            // Undoing a grown-up's decision is the one thing a child needs
            // their PIN for; undoing their own tick is free.
            if ($slot->isApproved()) {
                $this->dispatch('need-pin', member: $this->member->id, action: 'undo-chore', subject: $choreId);

                return;
            }

            $board->uncomplete($slot->instance);
            $this->justDone = null;
        } else {
            $board->complete($chore, $this->day, $this->member);
            $this->justDone = $choreId;
        }

        $this->refresh();
    }

    /** Called back once a PIN has been checked. */
    #[On('pin-accepted')]
    public function pinAccepted(string $action, int $subject): void
    {
        if ($action !== 'undo-chore' || ! $this->member) {
            return;
        }

        $slot = $this->chores->firstWhere(fn ($s) => $s->chore->id === $subject);

        if ($slot?->instance) {
            app(ChoreBoard::class)->uncomplete($slot->instance);
        }

        $this->refresh();
    }

    protected function choreFor(int $id): Chore
    {
        return Chore::where('household_id', Household::current()->id)
            ->where('member_id', $this->memberId)
            ->findOrFail($id);
    }

    protected function refresh(): void
    {
        unset($this->chores, $this->balance, $this->done, $this->allDone);

        $this->dispatch('chores-changed');
    }
}; ?>

<div>
    @if ($this->member)
        <div class="fixed inset-0 z-50 bg-slate-900/70 backdrop-blur-sm" wire:click="close" aria-hidden="true"></div>

        <div class="modal-viewport z-50" role="dialog" aria-modal="true" aria-label="{{ $this->member->name }}'s day">
            <div class="pane-scroll flex max-h-full w-full max-w-2xl flex-col overflow-y-auto rounded-3xl bg-white p-5 shadow-2xl dark:bg-slate-900">

                {{-- ---------------------------- WHO --------------------------- --}}
                <div class="flex shrink-0 items-center gap-4">
                    @if ($this->member->avatarUrl())
                        <img src="{{ $this->member->avatarUrl() }}" alt="" class="size-16 rounded-full object-cover">
                    @else
                        <span class="grid size-16 place-items-center rounded-full text-xl font-bold text-white"
                              style="background-color: {{ $this->member->colour }};">{{ $this->member->initials() }}</span>
                    @endif

                    <div class="min-w-0 flex-1">
                        <h2 class="truncate text-2xl font-bold">{{ $this->member->name }}</h2>
                        <p class="text-sm text-slate-500 dark:text-slate-400">
                            {{ $this->day->isSameDay(\App\Models\Household::current()->todayLocal()) ? 'Today' : $this->day->format('l j F') }}
                        </p>
                    </div>

                    <div class="shrink-0 text-right">
                        <p class="text-3xl font-bold tabular-nums" style="color: {{ $this->member->colour }};">{{ $this->balance }}</p>
                        <p class="text-xs font-semibold tracking-wide text-slate-400 uppercase">points</p>
                    </div>

                    <button type="button" wire:click="close"
                            class="grid touch-target shrink-0 place-items-center rounded-2xl text-slate-400" aria-label="Close">
                        <svg class="size-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M6 6l12 12M18 6 6 18" />
                        </svg>
                    </button>
                </div>

                {{-- --------------------------- CHORES ------------------------- --}}
                <div class="mt-5 min-h-0 flex-1">
                    @if ($this->chores->isEmpty())
                        <p class="py-10 text-center text-lg text-slate-400">Nothing to do today.</p>
                    @else
                        @if ($this->allDone)
                            {{-- Worth a fuss. The whole scheme runs on this moment. --}}
                            <div class="mb-4 rounded-2xl bg-emerald-50 p-4 text-center dark:bg-emerald-950/40">
                                <p class="text-2xl font-bold text-emerald-700 dark:text-emerald-300">All done! 🎉</p>
                                <p class="mt-0.5 text-sm text-emerald-700/80 dark:text-emerald-400/80">Everything ticked off for today.</p>
                            </div>
                        @else
                            <p class="mb-2 px-1 text-sm font-semibold tracking-wide text-slate-400 uppercase">
                                {{ $this->done }} of {{ $this->chores->count() }} done
                            </p>
                        @endif

                        <ul class="space-y-2.5">
                            @foreach ($this->chores as $slot)
                                <li wire:key="slot-{{ $slot->chore->id }}">
                                    <button
                                        type="button"
                                        wire:click="tick({{ $slot->chore->id }})"
                                        class="flex w-full items-center gap-4 rounded-2xl border-2 p-4 text-left transition-all
                                               {{ $slot->isDone()
                                                   ? 'border-transparent bg-slate-50 dark:bg-slate-800/60'
                                                   : 'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900' }}
                                               {{ $justDone === $slot->chore->id ? 'chore-just-done' : '' }}"
                                        style="{{ $slot->isDone() ? '' : 'border-color: '.$this->member->colour.'55;' }}"
                                    >
                                        <span class="grid size-12 shrink-0 place-items-center rounded-xl border-2 transition-colors
                                                     {{ $slot->isDone() ? 'border-transparent text-white' : 'border-slate-300 dark:border-slate-600' }}"
                                              style="{{ $slot->isDone() ? 'background-color: '.$this->member->colour.';' : '' }}">
                                            @if ($slot->isDone())
                                                <svg class="size-8" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path d="m5 12 5 5L20 7" />
                                                </svg>
                                            @endif
                                        </span>

                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-xl font-semibold {{ $slot->isDone() ? 'text-slate-400 line-through' : '' }}">
                                                @if ($slot->chore->icon) {{ $slot->chore->icon }} @endif{{ $slot->chore->title }}
                                            </span>
                                            @if ($slot->isAwaitingApproval())
                                                <span class="block text-sm font-medium text-amber-600 dark:text-amber-400">
                                                    Waiting to be checked
                                                </span>
                                            @elseif ($slot->isApproved())
                                                <span class="block text-sm text-slate-400">Checked ✓</span>
                                            @endif
                                        </span>

                                        @if ($slot->points() > 0)
                                            <span class="shrink-0 rounded-full px-3 py-1 text-lg font-bold tabular-nums
                                                         {{ $slot->isEarned() ? 'text-white' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' }}"
                                                  style="{{ $slot->isEarned() ? 'background-color: '.$this->member->colour.';' : '' }}">
                                                {{ $slot->points() }}
                                            </span>
                                        @endif
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
