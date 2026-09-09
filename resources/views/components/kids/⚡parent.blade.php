<?php

use App\Models\ChoreInstance;
use App\Models\Household;
use App\Models\Member;
use App\Models\Redemption;
use App\Services\Chores\ChoreBoard;
use App\Services\Points\PointsLedger;
use App\Services\Points\RewardShop;
use App\Support\AdultGate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The grown-ups' side: what is waiting to be checked, each child's day, and
 * the week so far.
 *
 * No PIN anywhere here. A parent reaching this has already signed in, and
 * asking again would be theatre.
 */
new #[Layout('layouts::app')] class extends Component
{
    public ?string $error = null;

    /** Which day each child's list is showing, keyed by member id. */
    public array $dayFor = [];

    /** The one child whose day picker is open, if any. */
    public ?int $pickingFor = null;

    public function household(): Household
    {
        return Household::current();
    }

    #[Computed]
    public function today(): CarbonImmutable
    {
        return $this->household()->todayLocal();
    }

    #[Computed]
    public function weekStart(): CarbonImmutable
    {
        return $this->household()->weekStart();
    }

    /**
     * @return list<array{date: string, carbon: CarbonImmutable, is_today: bool}>
     */
    #[Computed]
    public function days(): array
    {
        return array_map(function (int $offset) {
            $day = $this->weekStart->addDays($offset);

            return [
                'date' => $day->toDateString(),
                'carbon' => $day,
                'is_today' => $day->isSameDay($this->today),
            ];
        }, range(0, 6));
    }

    /** @return Collection<int, Member> */
    #[Computed]
    public function children(): Collection
    {
        return $this->household()->members()->children()->get();
    }

    /**
     * The whole week's chores in one pair of queries, however many days are
     * being looked at across however many children.
     *
     * @return Collection<string, Collection<int|string, Collection<int, \App\Services\Chores\ChoreSlot>>>
     */
    #[Computed]
    public function week(): Collection
    {
        return app(ChoreBoard::class)->forRange($this->household(), $this->weekStart, $this->weekStart->addDays(6));
    }

    /**
     * Everything done and not yet signed off, whatever day it was done on.
     *
     * Not limited to today: a chore ticked on Sunday evening needs approving
     * on Monday, and a queue that only looks at today strands it — the child
     * waits forever and nobody is ever shown the thing to check.
     *
     * @return Collection<int, ChoreInstance>
     */
    #[Computed]
    public function awaiting(): Collection
    {
        return app(ChoreBoard::class)->awaitingApproval($this->household());
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

    /** Which day a child's list is showing. Today until someone picks another. */
    public function dateFor(int $childId): string
    {
        $date = $this->dayFor[$childId] ?? null;

        return $this->isThisWeek((string) $date) ? (string) $date : $this->today->toDateString();
    }

    /** @return Collection<int, \App\Services\Chores\ChoreSlot> */
    public function slotsFor(Member $child): Collection
    {
        return $this->week[$this->dateFor($child->id)][$child->id] ?? collect();
    }

    public function pickDay(int $childId, string $date): void
    {
        if ($this->isThisWeek($date)) {
            $this->dayFor[$childId] = $date;
        }

        $this->pickingFor = null;
    }

    public function togglePicker(int $childId): void
    {
        $this->pickingFor = $this->pickingFor === $childId ? null : $childId;
    }

    /**
     * The week so far, per child.
     *
     * `pending` is the number that explains a child having done things and
     * saved nothing: points held back because nobody has checked them.
     *
     * @return Collection<int, array{member: Member, earned: int, pending: int, balance: int, done: int, pence: int}>
     */
    #[Computed]
    public function summary(): Collection
    {
        $ledger = app(PointsLedger::class);
        $board = app(ChoreBoard::class);
        $shop = app(RewardShop::class);
        $from = $this->weekStart;
        $to = $from->addDays(6);

        return $this->children->map(function (Member $child) use ($ledger, $board, $shop, $from, $to) {
            $earned = $ledger->earnedBetween($child, $from->toDateTimeString(), $from->addDays(7)->toDateTimeString());

            return [
                'member' => $child,
                'earned' => $earned,
                'pending' => $board->pendingPoints($child, $from->toDateString(), $to->toDateString()),
                'balance' => $ledger->balanceFor($child),
                'done' => ChoreInstance::where('member_id', $child->id)
                    ->whereBetween('on', [$from->toDateString(), $to->toDateString()])
                    ->done()
                    ->count(),
                'pence' => $shop->allowancePence($child, max($earned, 0)),
            ];
        })->values();
    }

    /* ------------------------------ actions ------------------------------ */

    /** Approve from the waiting list, which reaches back beyond this week. */
    public function approveInstance(int $instanceId): void
    {
        $this->askAdult('approve-chore', $instanceId);
    }

    public function grant(int $redemptionId): void
    {
        $this->askAdult('grant-reward', $redemptionId);
    }

    /** Called back once the keypad has checked a grown-up's PIN. */
    #[On('pin-accepted')]
    public function pinAccepted(string $action, int $subject): void
    {
        match ($action) {
            'approve-chore' => $this->doApprove($subject),
            'grant-reward' => $this->doGrant($subject),
            default => null,
        };
    }

    /**
     * Ask for a grown-up, unless we already know we have one.
     *
     * On a signed-in phone this never asks — proving it twice is theatre. On
     * the wall, which has no session and is used by children all day, it does.
     */
    protected function askAdult(string $action, int $subject): void
    {
        $this->error = null;

        // Nothing to check against would lock the household out of its own
        // approvals until somebody went and set a PIN.
        if (AdultGate::isTrusted() || ! $this->anyAdultHasAPin()) {
            $this->pinAccepted($action, $subject);

            return;
        }

        $this->dispatch('need-adult-pin', action: $action, subject: $subject);
    }

    protected function anyAdultHasAPin(): bool
    {
        return Member::where('household_id', $this->household()->id)
            ->where('is_child', false)
            ->whereNotNull('pin')
            ->exists();
    }

    protected function doApprove(int $instanceId): void
    {
        $instance = $this->instance($instanceId);

        if ($instance) {
            app(ChoreBoard::class)->approve($instance, $this->asMember());
        }

        $this->refresh();
    }

    public function undoInstance(int $instanceId): void
    {
        $instance = $this->instance($instanceId);

        if ($instance) {
            app(ChoreBoard::class)->uncomplete($instance);
        }

        $this->refresh();
    }

    public function toggle(int $choreId, string $date): void
    {
        $slot = $this->slot($choreId, $date);

        if (! $slot) {
            return;
        }

        $board = app(ChoreBoard::class);

        $slot->isDone()
            ? $board->uncomplete($slot->instance)
            : $board->complete($slot->chore, CarbonImmutable::parse($date), $this->asMember());

        $this->refresh();
    }

    public function approve(int $choreId, string $date): void
    {
        $slot = $this->slot($choreId, $date);

        if (! $slot) {
            return;
        }

        $board = app(ChoreBoard::class);

        $board->approve(
            $slot->instance ?? $board->instanceFor($slot->chore, CarbonImmutable::parse($date)),
            $this->asMember(),
        );

        $this->refresh();
    }

    protected function doGrant(int $redemptionId): void
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

    /* ------------------------------ helpers ------------------------------ */

    protected function slot(int $choreId, string $date): ?\App\Services\Chores\ChoreSlot
    {
        if (! $this->isThisWeek($date)) {
            return null;
        }

        return ($this->week[$date] ?? collect())
            ->flatten()
            ->first(fn ($slot) => $slot->chore->id === $choreId);
    }

    protected function instance(int $id): ?ChoreInstance
    {
        return ChoreInstance::query()
            ->whereHas('chore', fn ($q) => $q->where('household_id', $this->household()->id))
            ->find($id);
    }

    protected function requestFor(int $id): Redemption
    {
        return Redemption::where('household_id', $this->household()->id)->findOrFail($id);
    }

    /** Guards a date that arrived from the browser. */
    protected function isThisWeek(string $date): bool
    {
        return collect($this->days)->contains('date', $date);
    }

    /** The signed-in parent as a family member, when they are linked to one. */
    protected function asMember(): ?Member
    {
        return auth()->user()?->member;
    }

    protected function refresh(): void
    {
        unset($this->week, $this->awaiting, $this->requests, $this->summary);

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

    <div class="shrink-0 px-4 pb-2">
        <livewire:search.box />
    </div>

    <livewire:kids.pin />
    <livewire:kids.ledger />

    <div class="pane-scroll min-h-0 flex-1 space-y-4 px-4 pb-8">

        @if ($error)
            <p class="rounded-xl bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">{{ $error }}</p>
        @endif

        {{-- Waiting on a grown-up. Top of the page, because these are the only
             things on it actually asking for something — and they reach back
             beyond today, since a Sunday-evening tick needs Monday's approval. --}}
        @if ($this->awaiting->isNotEmpty() || $this->requests->isNotEmpty())
            <section class="rounded-2xl bg-white p-4 dark:bg-slate-900">
                <h2 class="font-semibold">Waiting for you</h2>

                <ul class="mt-2 divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($this->awaiting as $instance)
                        <li class="flex items-center gap-3 py-2" wire:key="await-{{ $instance->id }}">
                            <span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $instance->chore->member?->colour ?? '#94a3b8' }};"></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium">
                                    @if ($instance->chore->icon) {{ $instance->chore->icon }} @endif{{ $instance->chore->title }}
                                </span>
                                <span class="block text-sm text-slate-500 dark:text-slate-400">
                                    {{ $instance->member?->name ?? 'Anyone' }} ·
                                    {{ $instance->on->isSameDay($this->today)
                                        ? 'today'
                                        : ($instance->on->isSameDay($this->today->subDay()) ? 'yesterday' : $instance->on->format('D j M')) }}
                                    @if ($instance->points > 0) · {{ $instance->points }} points @endif
                                </span>
                            </span>
                            <button type="button" wire:click="approveInstance({{ $instance->id }})"
                                    class="shrink-0 touch-target rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white">Approve</button>
                            <button type="button" wire:click="undoInstance({{ $instance->id }})"
                                    class="shrink-0 touch-target rounded-xl px-2 text-sm font-semibold text-slate-500">Undo</button>
                        </li>
                    @endforeach

                    @foreach ($this->requests as $request)
                        <li class="flex items-center gap-3 py-2" wire:key="req-{{ $request->id }}">
                            <x-icon name="gift" class="size-5 shrink-0" />
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

        {{-- --------------------------- EACH CHILD -------------------------- --}}
        @forelse ($this->children as $child)
            @php
                $date = $this->dateFor($child->id);
                $slots = $this->slotsFor($child);
                $showing = \Carbon\CarbonImmutable::parse($date);
            @endphp

            <section class="rounded-2xl bg-white p-4 dark:bg-slate-900" wire:key="child-{{ $child->id }}">
                {{-- The card header is the day picker: a parent checking up on
                     Tuesday should not have to wait for Tuesday. --}}
                <button type="button" wire:click="togglePicker({{ $child->id }})"
                        class="flex w-full items-center gap-3 text-left">
                    @if ($child->avatarUrl())
                        <img src="{{ $child->avatarUrl() }}" alt="" class="size-10 shrink-0 rounded-full object-cover">
                    @else
                        <span class="grid size-10 shrink-0 place-items-center rounded-full text-sm font-bold text-white"
                              style="background-color: {{ $child->colour }};">{{ $child->initials() }}</span>
                    @endif

                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-semibold">{{ $child->name }}</span>
                        <span class="block text-sm text-slate-500 dark:text-slate-400">
                            {{ $showing->isSameDay($this->today) ? 'Today' : $showing->format('l j M') }} ·
                            {{ $slots->filter(fn ($s) => $s->isDone())->count() }}/{{ $slots->count() }} done
                        </span>
                    </span>

                    <svg class="size-5 shrink-0 text-slate-400 transition-transform {{ $pickingFor === $child->id ? 'rotate-90' : '' }}"
                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="m9 6 6 6-6 6" />
                    </svg>
                </button>

                {{-- The same ledger the child reaches from their own view, so a
                     parent answering "where did my stars go" is looking at
                     exactly what the child is looking at. --}}
                <button type="button"
                        wire:click="$dispatch('show-ledger', { member: {{ $child->id }} })"
                        class="mt-1 touch-target text-sm font-semibold text-blue-600 dark:text-blue-400">
                    Points history
                </button>

                @if ($pickingFor === $child->id)
                    <div class="mt-3 grid grid-cols-7 gap-1">
                        @foreach ($this->days as $day)
                            <button type="button" wire:click="pickDay({{ $child->id }}, '{{ $day['date'] }}')"
                                    class="flex touch-target flex-col items-center justify-center rounded-xl py-1.5 text-xs font-semibold transition-colors
                                           {{ $day['date'] === $date
                                               ? 'bg-blue-600 text-white'
                                               : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' }}">
                                <span class="opacity-70 {{ $day['is_today'] ? 'underline underline-offset-2' : '' }}">{{ $day['carbon']->format('D') }}</span>
                                <span class="text-sm tabular-nums">{{ $day['carbon']->format('j') }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif

                @if ($slots->isEmpty())
                    <p class="mt-2 text-sm text-slate-400">
                        Nothing due {{ $showing->isSameDay($this->today) ? 'today' : 'that day' }}.
                    </p>
                @else
                    <ul class="mt-2 divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($slots as $slot)
                            <li class="flex items-center gap-3 py-2" wire:key="slot-{{ $slot->chore->id }}-{{ $date }}">
                                <button type="button"
                                        wire:click="toggle({{ $slot->chore->id }}, '{{ $date }}')"
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
                                    @elseif ($slot->isAwaitingApproval())
                                        <span class="block text-xs font-medium text-amber-600 dark:text-amber-400">Pending your check</span>
                                    @endif
                                </span>

                                @if ($slot->isAwaitingApproval())
                                    <button type="button" wire:click="approve({{ $slot->chore->id }}, '{{ $date }}')"
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
                    From {{ $this->weekStart->format('D j M') }}
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
                                {{-- Says why a child can have done things and
                                     saved nothing: the points exist, nobody
                                     has released them. --}}
                                @if ($row['pending'] > 0)
                                    <span class="block text-sm font-medium text-amber-600 dark:text-amber-400">
                                        {{ $row['pending'] }} pending your check
                                    </span>
                                @endif
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
