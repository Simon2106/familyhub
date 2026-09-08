<?php

use App\Models\Chore;
use App\Models\Household;
use App\Models\Member;
use App\Models\Reward;
use App\Models\RoutineStep;
use App\Services\Chores\ChoreBoard;
use App\Services\Points\RewardShop;
use App\Services\Routines\RoutineBoard;
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

    public ?int $justDoneStep = null;

    public ?string $redeemError = null;

    #[On('show-my-day')]
    public function show(int $member, ?string $date = null): void
    {
        $this->memberId = $member;
        $this->date = $date ?: Household::current()->todayLocal()->toDateString();
        $this->justDone = null;
        $this->justDoneStep = null;
        $this->redeemError = null;

        unset($this->member, $this->chores, $this->balance, $this->routines, $this->pendingRedemptions);
    }

    public function close(): void
    {
        $this->reset(['memberId', 'date', 'justDone', 'justDoneStep', 'redeemError']);
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

    /**
     * This child's routines for the day, the running one first.
     *
     * @return Collection<int, \App\Services\Routines\RoutineProgress>
     */
    #[Computed]
    public function routines(): Collection
    {
        if (! $this->member) {
            return collect();
        }

        return app(RoutineBoard::class)
            ->forMember($this->member, $this->day)
            ->sortByDesc(fn ($progress) => $progress->isNow ? 1 : 0)
            ->values();
    }

    /** Ticking a routine step is as free as ticking a chore. */
    public function tickStep(int $stepId): void
    {
        $step = RoutineStep::query()
            ->whereHas('routine', fn ($q) => $q->where('member_id', $this->memberId))
            ->findOrFail($stepId);

        $board = app(RoutineBoard::class);

        if ($board->isComplete($step, $this->day)) {
            $board->uncomplete($step, $this->day);
            $this->justDoneStep = null;
        } else {
            $board->complete($step, $this->day);
            $this->justDoneStep = $stepId;
        }

        unset($this->routines);

        $this->dispatch('routines-changed');
    }

    #[Computed]
    public function balance(): int
    {
        return $this->member ? app(PointsLedger::class)->balanceFor($this->member) : 0;
    }

    /** @return Collection<int, Reward> */
    #[Computed]
    public function rewards(): Collection
    {
        return Reward::query()
            ->where('household_id', Household::current()->id)
            ->active()
            ->orderBy('cost')
            ->get();
    }

    /** @return Collection<int, \App\Models\Redemption> */
    #[Computed]
    public function pendingRedemptions(): Collection
    {
        return $this->member
            ? \App\Models\Redemption::where('member_id', $this->member->id)->pending()->get()
            : collect();
    }

    /**
     * Ask to spend points.
     *
     * The child's own PIN, because in a house with two children and one
     * wall-mounted screen the thing worth guarding is a sibling emptying your
     * savings — not you spending them.
     */
    public function askFor(int $rewardId): void
    {
        $this->redeemError = null;

        $this->dispatch('need-pin', member: $this->member->id, action: 'redeem', subject: $rewardId);
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
        if (! $this->member) {
            return;
        }

        match ($action) {
            'undo-chore' => $this->undoChore($subject),
            'redeem' => $this->redeem($subject),
            'grant-redemption' => $this->grantRedemption($subject),
            default => null,
        };
    }

    protected function undoChore(int $choreId): void
    {
        $slot = $this->chores->firstWhere(fn ($s) => $s->chore->id === $choreId);

        if ($slot?->instance) {
            app(ChoreBoard::class)->uncomplete($slot->instance);
        }

        $this->refresh();
    }

    /** A grown-up standing in the kitchen says yes. */
    public function grant(int $redemptionId): void
    {
        $this->redeemError = null;

        $this->dispatch('need-adult-pin', action: 'grant-redemption', subject: $redemptionId);
    }

    protected function grantRedemption(int $redemptionId): void
    {
        $redemption = \App\Models\Redemption::where('member_id', $this->memberId)->pending()->find($redemptionId);

        if (! $redemption) {
            return;
        }

        try {
            app(RewardShop::class)->grant($redemption, null);
        } catch (Throwable $e) {
            $this->redeemError = $e->getMessage();

            return;
        }

        unset($this->pendingRedemptions, $this->balance);

        $this->dispatch('redemptions-changed');
    }

    protected function redeem(int $rewardId): void
    {
        $reward = Reward::where('household_id', Household::current()->id)->active()->find($rewardId);

        if (! $reward) {
            return;
        }

        try {
            app(RewardShop::class)->request($this->member, $reward);
        } catch (Throwable $e) {
            $this->redeemError = $e->getMessage();

            return;
        }

        unset($this->pendingRedemptions);

        $this->dispatch('redemptions-changed');
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
        <x-modal dismiss="close" :label="$this->member->name.'\'s day'" width="max-w-2xl" class="rounded-3xl">
            <div class="flex min-h-0 flex-col p-5">

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

                    {{-- The total is the way into the history behind it: "where
                         did my stars go" is exactly the question an append-only
                         ledger exists to answer. --}}
                    <button type="button"
                            wire:click="$dispatch('show-ledger', { member: {{ $this->member->id }} })"
                            class="shrink-0 rounded-2xl px-2 py-1 text-right"
                            aria-label="{{ $this->member->name }}'s points history">
                        <span class="block text-3xl font-bold tabular-nums" style="color: {{ $this->member->colour }};">{{ $this->balance }}</span>
                        <span class="block text-xs font-semibold tracking-wide text-slate-400 uppercase">points ›</span>
                    </button>

                    <button type="button" wire:click="close"
                            class="grid touch-target shrink-0 place-items-center rounded-2xl text-slate-400" aria-label="Close">
                        <svg class="size-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M6 6l12 12M18 6 6 18" />
                        </svg>
                    </button>
                </div>

                {{-- -------------------------- ROUTINES ------------------------ --}}
                @foreach ($this->routines as $progress)
                    <section class="mt-5 rounded-2xl p-4 {{ $progress->isNow ? 'ring-2' : 'bg-slate-50 dark:bg-slate-800/40' }}"
                             style="{{ $progress->isNow ? 'background-color: '.$this->member->colour.'12; --tw-ring-color: '.$this->member->colour.'66;' : '' }}"
                             wire:key="routine-{{ $progress->routine->id }}">
                        <div class="flex items-baseline gap-2">
                            <h3 class="text-lg font-bold">{{ $progress->routine->label() }}</h3>
                            @if ($progress->isNow)
                                <span class="rounded-full px-2 py-0.5 text-xs font-bold text-white"
                                      style="background-color: {{ $this->member->colour }};">now</span>
                            @else
                                <span class="text-sm text-slate-400">{{ $progress->routine->windowLabel() }}</span>
                            @endif
                            <span class="ml-auto text-sm font-semibold tabular-nums text-slate-400">{{ $progress->summary() }}</span>
                        </div>

                        @if ($progress->allDone())
                            <p class="mt-2 text-center text-lg font-bold text-emerald-600 dark:text-emerald-400">
                                {{ $progress->routine->label() }} all done! 🎉
                            </p>
                        @endif

                        <ul class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
                            @foreach ($progress->steps as $entry)
                                @php $step = $entry['step']; @endphp
                                <li wire:key="step-{{ $step->id }}">
                                    <button
                                        type="button"
                                        wire:click="tickStep({{ $step->id }})"
                                        class="flex w-full flex-col items-center gap-1 rounded-2xl border-2 p-3 transition-all
                                               {{ $entry['done'] ? 'border-transparent' : 'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900' }}
                                               {{ $justDoneStep === $step->id ? 'chore-just-done' : '' }}"
                                        style="{{ $entry['done'] ? 'background-color: '.$this->member->colour.'; color: white;' : '' }}"
                                    >
                                        <span class="text-3xl leading-none" aria-hidden="true">{{ $step->icon ?: ($entry['done'] ? '✅' : '⬜️') }}</span>
                                        <span class="text-center text-sm leading-tight font-semibold {{ $entry['done'] ? '' : 'text-slate-700 dark:text-slate-200' }}">
                                            {{ $step->title }}
                                        </span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach

                {{-- --------------------------- CHORES ------------------------- --}}
                <div class="mt-5 min-h-0 flex-1">
                    @if ($this->chores->isEmpty())
                        @if ($this->routines->isEmpty())
                            <p class="py-10 text-center text-lg text-slate-400">Nothing to do today.</p>
                        @endif
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

                {{-- ---------------------------- REWARDS ----------------------- --}}
                @if ($this->rewards->isNotEmpty())
                    <section class="mt-6 border-t border-slate-100 pt-4 dark:border-slate-800">
                        <div class="flex items-baseline gap-2">
                            <h3 class="text-lg font-bold">Spend your points</h3>
                            @if (\App\Models\Household::current()->allowanceEnabled())
                                <span class="text-sm text-slate-400">
                                    worth £{{ number_format(app(\App\Services\Points\RewardShop::class)->allowancePence($this->member, $this->balance) / 100, 2) }}
                                </span>
                            @endif
                        </div>

                        @if ($redeemError)
                            <p class="mt-2 rounded-xl bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
                                {{ $redeemError }}
                            </p>
                        @endif

                        {{-- Pending requests sit here with a Grant button, so a
                             parent already at the wall can settle it rather than
                             going to find a phone. --}}
                        @foreach ($this->pendingRedemptions as $pending)
                            <div class="mt-2 flex items-center gap-2 rounded-xl bg-amber-50 px-3 py-2 dark:bg-amber-950/40"
                                 wire:key="pending-{{ $pending->id }}">
                                <span class="min-w-0 flex-1 text-sm font-medium text-amber-900 dark:text-amber-200">
                                    {{ $pending->name }} · {{ $pending->cost }} points · waiting for a grown-up
                                </span>
                                <button type="button" wire:click="grant({{ $pending->id }})"
                                        class="shrink-0 touch-target rounded-xl bg-amber-600 px-3 text-sm font-semibold text-white">
                                    Grant
                                </button>
                            </div>
                        @endforeach

                        <ul class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
                            @foreach ($this->rewards as $reward)
                                @php $affordable = $this->balance >= $reward->cost; @endphp
                                <li wire:key="reward-{{ $reward->id }}">
                                    <button
                                        type="button"
                                        wire:click="askFor({{ $reward->id }})"
                                        @disabled(! $affordable)
                                        class="flex w-full flex-col items-center gap-1 overflow-hidden rounded-2xl border-2 p-3 text-center
                                               {{ $affordable ? 'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900' : 'border-transparent bg-slate-50 opacity-50 dark:bg-slate-800/60' }}"
                                    >
                                        @if ($reward->imageUrl())
                                            <img src="{{ $reward->imageUrl() }}" alt="" class="size-14 rounded-xl object-cover">
                                        @else
                                            <span class="text-3xl leading-none" aria-hidden="true">🎁</span>
                                        @endif
                                        <span class="text-sm leading-tight font-semibold">{{ $reward->name }}</span>
                                        <span class="rounded-full px-2 py-0.5 text-sm font-bold tabular-nums
                                                     {{ $affordable ? 'text-white' : 'bg-slate-200 text-slate-500 dark:bg-slate-700' }}"
                                              style="{{ $affordable ? 'background-color: '.$this->member->colour.';' : '' }}">
                                            {{ $reward->cost }}
                                        </span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            </div>
        </x-modal>
    @endif
</div>
