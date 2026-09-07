<?php

use App\Models\ChoreInstance;
use App\Models\Household;
use App\Models\Member;
use App\Models\Redemption;
use App\Services\Chores\ChoreBoard;
use App\Services\Points\PointsLedger;
use App\Services\Points\RewardShop;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The grown-ups' side: today's chores across every child, and the week so far.
 *
 * No PIN anywhere here. A parent reaching this has already signed in, and
 * asking again would be theatre.
 */
new #[Layout('layouts::app')] class extends Component
{
    public ?string $error = null;

    public function household(): Household
    {
        return Household::current();
    }

    #[Computed]
    public function today(): CarbonImmutable
    {
        return $this->household()->todayLocal();
    }

    /** @return Collection<int, Member> */
    #[Computed]
    public function children(): Collection
    {
        return $this->household()->members()->children()->get();
    }

    /**
     * Today's chores, by child.
     *
     * @return Collection<int, Collection<int, \App\Services\Chores\ChoreSlot>>
     */
    #[Computed]
    public function board(): Collection
    {
        return app(ChoreBoard::class)
            ->forDay($this->household(), $this->today)
            ->groupBy(fn ($slot) => $slot->chore->member_id ?? 0);
    }

    /** The ones actually asking for a decision, which is what to lead with. */
    #[Computed]
    public function awaiting(): Collection
    {
        return $this->board->flatten()->filter(fn ($slot) => $slot->isAwaitingApproval())->values();
    }

    /** @return Collection<int, Redemption> */
    #[Computed]
    public function requests(): Collection
    {
        return Redemption::where('household_id', $this->household()->id)
            ->pending()
            ->with('member')
            ->orderBy('requested_at')
            ->get();
    }

    /**
     * The week so far, per child.
     *
     * @return Collection<int, array{member: Member, earned: int, balance: int, done: int, pence: int}>
     */
    #[Computed]
    public function summary(): Collection
    {
        $ledger = app(PointsLedger::class);
        $shop = app(RewardShop::class);
        $from = $this->household()->weekStart();
        $to = $from->addDays(7);

        return $this->children->map(function (Member $child) use ($ledger, $shop, $from, $to) {
            $earned = $ledger->earnedBetween($child, $from->toDateTimeString(), $to->toDateTimeString());

            return [
                'member' => $child,
                'earned' => $earned,
                'balance' => $ledger->balanceFor($child),
                'done' => ChoreInstance::where('member_id', $child->id)
                    ->whereBetween('on', [$from->toDateString(), $to->subDay()->toDateString()])
                    ->done()
                    ->count(),
                'pence' => $shop->allowancePence($child, max($earned, 0)),
            ];
        })->values();
    }

    public function approve(int $choreId): void
    {
        $slot = $this->board->flatten()->firstWhere(fn ($s) => $s->chore->id === $choreId);

        if ($slot) {
            app(ChoreBoard::class)->approve(
                $slot->instance ?? app(ChoreBoard::class)->instanceFor($slot->chore, $this->today),
                $this->asMember(),
            );
        }

        $this->refresh();
    }

    /** Undo a tick, whoever made it. A parent needs no PIN for this. */
    public function undo(int $choreId): void
    {
        $slot = $this->board->flatten()->firstWhere(fn ($s) => $s->chore->id === $choreId);

        if ($slot?->instance) {
            app(ChoreBoard::class)->uncomplete($slot->instance);
        }

        $this->refresh();
    }

    public function tick(int $choreId): void
    {
        $slot = $this->board->flatten()->firstWhere(fn ($s) => $s->chore->id === $choreId);

        if ($slot && ! $slot->isDone()) {
            app(ChoreBoard::class)->complete($slot->chore, $this->today, $this->asMember());
        }

        $this->refresh();
    }

    public function grant(int $redemptionId): void
    {
        $this->error = null;

        try {
            app(RewardShop::class)->grant($this->requestFor($redemptionId), $this->asMember());
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
        }

        $this->refresh();
    }

    public function decline(int $redemptionId): void
    {
        app(RewardShop::class)->decline($this->requestFor($redemptionId), $this->asMember());

        $this->refresh();
    }

    protected function requestFor(int $id): Redemption
    {
        return Redemption::where('household_id', $this->household()->id)->findOrFail($id);
    }

    /** The signed-in parent as a family member, when they are linked to one. */
    protected function asMember(): ?Member
    {
        return auth()->user()?->member;
    }

    protected function refresh(): void
    {
        unset($this->board, $this->awaiting, $this->requests, $this->summary);

        $this->dispatch('chores-changed');
    }
}; ?>

<div class="app-shell flex flex-col">
    <header class="flex shrink-0 items-center gap-3 px-4 pt-4 pb-2">
        <a href="{{ route('app') }}" wire:navigate
           class="grid touch-target place-items-center rounded-xl bg-white text-slate-500 dark:bg-slate-900" aria-label="Back">
            <svg class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                <path d="m15 18-6-6 6-6" />
            </svg>
        </a>
        <h1 class="flex-1 text-2xl font-bold">Kids</h1>
    </header>

    <div class="pane-scroll min-h-0 flex-1 space-y-4 px-4 pb-8">

        @if ($error)
            <p class="rounded-xl bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">{{ $error }}</p>
        @endif

        {{-- Waiting on a grown-up. Top of the page, because these are the only
             things on it that are actually asking for something. --}}
        @if ($this->awaiting->isNotEmpty() || $this->requests->isNotEmpty())
            <section class="rounded-2xl bg-white p-4 dark:bg-slate-900">
                <h2 class="font-semibold">Waiting for you</h2>

                <ul class="mt-2 space-y-2">
                    @foreach ($this->awaiting as $slot)
                        <li class="flex items-center gap-3" wire:key="await-{{ $slot->chore->id }}">
                            <span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $slot->chore->member?->colour }};"></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium">{{ $slot->chore->title }}</span>
                                <span class="block text-sm text-slate-500 dark:text-slate-400">
                                    {{ $slot->chore->member?->name }} · {{ $slot->points() }} points
                                </span>
                            </span>
                            <button type="button" wire:click="approve({{ $slot->chore->id }})"
                                    class="shrink-0 touch-target rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white">Approve</button>
                            <button type="button" wire:click="undo({{ $slot->chore->id }})"
                                    class="shrink-0 touch-target rounded-xl px-2 text-sm font-semibold text-slate-500">Undo</button>
                        </li>
                    @endforeach

                    @foreach ($this->requests as $request)
                        <li class="flex items-center gap-3" wire:key="req-{{ $request->id }}">
                            <span class="shrink-0 text-lg" aria-hidden="true">🎁</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium">{{ $request->name }}</span>
                                <span class="block text-sm text-slate-500 dark:text-slate-400">
                                    {{ $request->member?->name }} · {{ $request->cost }} points
                                </span>
                            </span>
                            <button type="button" wire:click="grant({{ $request->id }})"
                                    class="shrink-0 touch-target rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white">Grant</button>
                            <button type="button" wire:click="decline({{ $request->id }})"
                                    class="shrink-0 touch-target rounded-xl px-2 text-sm font-semibold text-slate-500">No</button>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- ------------------------- TODAY, BY CHILD ------------------------ --}}
        @forelse ($this->children as $child)
            @php $slots = $this->board[$child->id] ?? collect(); @endphp

            <section class="rounded-2xl bg-white p-4 dark:bg-slate-900" wire:key="child-{{ $child->id }}">
                <div class="flex items-center gap-3">
                    @if ($child->avatarUrl())
                        <img src="{{ $child->avatarUrl() }}" alt="" class="size-10 rounded-full object-cover">
                    @else
                        <span class="grid size-10 place-items-center rounded-full text-sm font-bold text-white"
                              style="background-color: {{ $child->colour }};">{{ $child->initials() }}</span>
                    @endif
                    <h2 class="min-w-0 flex-1 truncate font-semibold">{{ $child->name }}</h2>
                    <span class="shrink-0 text-sm text-slate-400">
                        {{ $slots->filter(fn ($s) => $s->isDone())->count() }}/{{ $slots->count() }} today
                    </span>
                </div>

                @if ($slots->isEmpty())
                    <p class="mt-2 text-sm text-slate-400">Nothing due today.</p>
                @else
                    <ul class="mt-2 divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($slots as $slot)
                            <li class="flex items-center gap-3 py-2" wire:key="slot-{{ $slot->chore->id }}">
                                <button type="button"
                                        wire:click="{{ $slot->isDone() ? 'undo' : 'tick' }}({{ $slot->chore->id }})"
                                        class="grid size-7 shrink-0 place-items-center rounded-lg border-2 {{ $slot->isDone() ? 'border-transparent text-white' : 'border-slate-300 dark:border-slate-600' }}"
                                        style="{{ $slot->isDone() ? 'background-color: '.$child->colour.';' : '' }}"
                                        aria-label="{{ $slot->isDone() ? 'Undo' : 'Tick' }} {{ $slot->chore->title }}">
                                    @if ($slot->isDone())
                                        <svg class="size-4" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 5 5L20 7" /></svg>
                                    @endif
                                </button>

                                <span class="min-w-0 flex-1">
                                    <span class="block truncate {{ $slot->isDone() ? 'text-slate-400 line-through' : 'font-medium' }}">
                                        @if ($slot->chore->icon) {{ $slot->chore->icon }} @endif{{ $slot->chore->title }}
                                    </span>
                                    @if ($slot->isApproved())
                                        <span class="block text-xs text-slate-400">Approved</span>
                                    @endif
                                </span>

                                @if ($slot->isAwaitingApproval())
                                    <button type="button" wire:click="approve({{ $slot->chore->id }})"
                                            class="shrink-0 touch-target rounded-xl bg-blue-600 px-3 text-sm font-semibold text-white">Approve</button>
                                @elseif ($slot->points() > 0)
                                    <span class="shrink-0 text-sm font-bold tabular-nums text-slate-400">{{ $slot->points() }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @empty
            <div class="rounded-2xl border-2 border-dashed border-slate-200 p-10 text-center dark:border-slate-800">
                <p class="font-semibold text-slate-400">No children set up yet.</p>
                <p class="mt-1 text-sm text-slate-400">Mark a family member as a child in Settings.</p>
            </div>
        @endforelse

        {{-- --------------------------- THIS WEEK --------------------------- --}}
        @if ($this->children->isNotEmpty())
            <section class="rounded-2xl bg-white p-4 dark:bg-slate-900">
                <h2 class="font-semibold">This week</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    From {{ $this->household()->weekStart()->format('D j M') }}
                </p>

                <ul class="mt-3 space-y-3">
                    @foreach ($this->summary as $row)
                        <li class="flex items-center gap-3" wire:key="sum-{{ $row['member']->id }}">
                            <span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $row['member']->colour }};"></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium">{{ $row['member']->name }}</span>
                                <span class="block text-sm text-slate-500 dark:text-slate-400">
                                    {{ trans_choice('{1}:count chore|[2,*]:count chores', $row['done'], ['count' => $row['done']]) }} done ·
                                    {{ $row['balance'] }} points saved
                                </span>
                            </span>
                            <span class="shrink-0 text-right">
                                <span class="block text-lg font-bold tabular-nums" style="color: {{ $row['member']->colour }};">
                                    {{ $row['earned'] >= 0 ? '+' : '' }}{{ $row['earned'] }}
                                </span>
                                @if ($this->household()->allowanceEnabled())
                                    <span class="block text-sm font-semibold tabular-nums text-slate-500 dark:text-slate-400">
                                        £{{ number_format($row['pence'] / 100, 2) }}
                                    </span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</div>
