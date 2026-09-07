<?php

use App\Models\Household;
use App\Models\Member;
use App\Services\Points\PointsLedger;
use App\Services\Points\RewardShop;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Where a child's stars went.
 *
 * The reason the ledger is append-only in the first place: a balance that only
 * ever shows a number is something a child has to take on trust, and taking a
 * seven-year-old's points on trust is how a points scheme stops being believed
 * in. Read-only, and no PIN — this is their own history, and putting a lock on
 * looking at it would say the opposite of what it is for.
 */
new class extends Component
{
    public ?int $memberId = null;

    /** Grows on demand rather than paging: a ledger is read by scrolling. */
    public int $limit = 50;

    public const PAGE = 50;

    #[On('show-ledger')]
    public function show(int $member): void
    {
        $this->memberId = $member;
        $this->limit = self::PAGE;

        unset($this->member, $this->rows, $this->total);
    }

    public function close(): void
    {
        $this->reset(['memberId', 'limit']);
    }

    public function showEarlier(): void
    {
        $this->limit += self::PAGE;

        unset($this->rows);
    }

    #[Computed]
    public function member(): ?Member
    {
        return $this->memberId
            ? Member::where('household_id', Household::current()->id)->find($this->memberId)
            : null;
    }

    /** @return Collection<int, array{entry: \App\Models\PointEntry, balance: int}> */
    #[Computed]
    public function rows(): Collection
    {
        return $this->member
            ? app(PointsLedger::class)->history($this->member, $this->limit)
            : collect();
    }

    #[Computed]
    public function total(): int
    {
        return $this->member ? app(PointsLedger::class)->countEntries($this->member) : 0;
    }

    #[Computed]
    public function balance(): int
    {
        return $this->member ? app(PointsLedger::class)->balanceFor($this->member) : 0;
    }

    /** Kept in step with the wall while it is open. */
    #[On('chores-changed')]
    #[On('redemptions-changed')]
    public function refresh(): void
    {
        unset($this->rows, $this->total, $this->balance);
    }
}; ?>

<div>
    @if ($this->member)
        <div class="fixed inset-0 z-[55] bg-slate-900/70 backdrop-blur-sm" wire:click="close" aria-hidden="true"></div>

        <div class="modal-viewport z-[55]" role="dialog" aria-modal="true" aria-label="{{ $this->member->name }}'s points">
            <div class="flex max-h-full w-full max-w-lg flex-col overflow-hidden rounded-3xl bg-white shadow-2xl dark:bg-slate-900">

                {{-- ---------------------------- HEADER ------------------------ --}}
                <div class="flex shrink-0 items-center gap-3 p-5 pb-3">
                    <button type="button" wire:click="close"
                            class="grid touch-target shrink-0 place-items-center rounded-2xl text-slate-400" aria-label="Back">
                        <svg class="size-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="m15 18-6-6 6-6" />
                        </svg>
                    </button>

                    <div class="min-w-0 flex-1">
                        <h2 class="truncate text-xl font-bold">{{ $this->member->name }}'s points</h2>
                        <p class="text-sm text-slate-500 dark:text-slate-400">
                            {{ trans_choice('{1}:count entry|[2,*]:count entries', $this->total, ['count' => $this->total]) }}
                        </p>
                    </div>

                    <div class="shrink-0 text-right">
                        <p class="text-3xl font-bold tabular-nums" style="color: {{ $this->member->colour }};">{{ $this->balance }}</p>
                        @if (\App\Models\Household::current()->allowanceEnabled())
                            <p class="text-xs font-semibold text-slate-400">
                                £{{ number_format(app(RewardShop::class)->allowancePence($this->member, $this->balance) / 100, 2) }}
                            </p>
                        @endif
                    </div>
                </div>

                {{-- ---------------------------- ENTRIES ----------------------- --}}
                <div class="pane-scroll min-h-0 flex-1 px-5 pb-5">
                    @if ($this->rows->isEmpty())
                        <p class="py-12 text-center text-slate-400">
                            No points yet. Tick something off and it will show up here.
                        </p>
                    @else
                        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                            @php $lastDate = null; @endphp

                            @foreach ($this->rows as $row)
                                @php
                                    $entry = $row['entry'];
                                    $on = $entry->created_at->timezone($this->member->household->displayTimezone());
                                    $date = $on->toDateString();
                                    $newDay = $date !== $lastDate;
                                    $lastDate = $date;
                                @endphp

                                @if ($newDay)
                                    <li class="pt-3 pb-1" wire:key="day-{{ $date }}">
                                        <p class="text-xs font-semibold tracking-wide text-slate-400 uppercase">
                                            {{ $on->isToday() ? 'Today' : ($on->isYesterday() ? 'Yesterday' : $on->format('D j M Y')) }}
                                        </p>
                                    </li>
                                @endif

                                <li class="flex items-center gap-3 py-2.5" wire:key="entry-{{ $entry->id }}">
                                    {{-- Earned and spent should be tellable
                                         apart at a glance, before the number
                                         is read. --}}
                                    <span class="grid size-9 shrink-0 place-items-center rounded-xl text-sm
                                                 {{ $entry->points >= 0
                                                     ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-400'
                                                     : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' }}"
                                          aria-hidden="true">
                                        {{ match ($entry->kind) {
                                            'redemption' => '🎁',
                                            'reversal' => '↩',
                                            'adjustment' => '✎',
                                            default => '★',
                                        } }}
                                    </span>

                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate font-medium">{{ $entry->reason }}</span>
                                        <span class="block text-xs text-slate-400">
                                            {{ $on->format('H:i') }}@if ($entry->kind === 'reversal') · taken back @elseif ($entry->kind === 'adjustment') · by a grown-up @endif
                                        </span>
                                    </span>

                                    <span class="shrink-0 text-right">
                                        <span class="block text-base font-bold tabular-nums
                                                     {{ $entry->points >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-500 dark:text-slate-400' }}">
                                            {{ $entry->points >= 0 ? '+' : '' }}{{ $entry->points }}
                                        </span>
                                        {{-- What they had after this happened,
                                             which is what makes it a ledger
                                             rather than a list. --}}
                                        <span class="block text-xs tabular-nums text-slate-400">{{ $row['balance'] }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>

                        @if ($this->total > $this->rows->count())
                            <button type="button" wire:click="showEarlier"
                                    class="mt-3 w-full touch-target rounded-xl bg-slate-100 font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                Show earlier
                            </button>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
