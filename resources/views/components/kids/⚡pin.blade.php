<?php

use App\Models\Household;
use App\Models\Member;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The keypad, shown only when something actually needs guarding.
 *
 * Ticking your own chores never comes through here — a scheme where doing your
 * jobs needs a password is a scheme nobody uses. This is for spending points
 * and for undoing something a grown-up decided.
 */
new class extends Component
{
    /** The member whose PIN is wanted, or null for "any grown-up". */
    public ?int $memberId = null;

    /** True when any adult's PIN will do, rather than one person's. */
    public bool $anyAdult = false;

    /** What the PIN is being asked for, handed back on success. */
    public string $action = '';

    public int $subject = 0;

    public string $entered = '';

    public ?string $error = null;

    #[On('need-pin')]
    public function ask(int $member, string $action, int $subject = 0): void
    {
        $this->memberId = $member;
        $this->anyAdult = false;
        $this->action = $action;
        $this->subject = $subject;
        $this->entered = '';
        $this->error = null;

        unset($this->member, $this->adults);
    }

    /**
     * Ask for a grown-up, without saying which.
     *
     * Whichever parent is standing in the kitchen should be able to say yes;
     * making the child fetch a specific one would be worse than useless.
     */
    #[On('need-adult-pin')]
    public function askAnyAdult(string $action, int $subject = 0): void
    {
        $this->memberId = null;
        $this->anyAdult = true;
        $this->action = $action;
        $this->subject = $subject;
        $this->entered = '';
        $this->error = null;

        unset($this->member, $this->adults);
    }

    #[Computed]
    public function member(): ?Member
    {
        return $this->memberId
            ? Member::where('household_id', Household::current()->id)->find($this->memberId)
            : null;
    }

    /** @return \Illuminate\Support\Collection<int, Member> */
    #[Computed]
    public function adults(): \Illuminate\Support\Collection
    {
        return Member::where('household_id', Household::current()->id)
            ->where('is_child', false)
            ->whereNotNull('pin')
            ->get();
    }

    /** Whether the pad is open at all. */
    public function isOpen(): bool
    {
        return $this->member !== null || $this->anyAdult;
    }

    public function heading(): string
    {
        return $this->anyAdult ? "A grown-up's PIN" : $this->member->name."'s PIN";
    }

    /** The colour the dots take, which is whose PIN it is. */
    public function tint(): string
    {
        return $this->member?->colour ?? '#2563eb';
    }

    /** What the keypad is about to let them do, in their own words. */
    #[Computed]
    public function prompt(): string
    {
        return match ($this->action) {
            'undo-chore' => 'Undo a chore a grown-up has checked',
            'redeem' => 'Spend your points',
            'grant-redemption' => 'Hand over a reward',
            default => 'Confirm it is you',
        };
    }

    public function press(string $digit): void
    {
        if (mb_strlen($this->entered) >= 6) {
            return;
        }

        $this->entered .= $digit;
        $this->error = null;

        // Four is the usual length; try it as soon as it could be right rather
        // than making a child hunt for an enter key.
        if (mb_strlen($this->entered) >= 4) {
            $this->submit();
        }
    }

    public function rub(): void
    {
        $this->entered = mb_substr($this->entered, 0, -1);
        $this->error = null;
    }

    public function submit(): void
    {
        if (! $this->accepts($this->entered)) {
            // Only wrong once it is at least as long as a PIN, so typing the
            // first digit does not flash an error.
            if (mb_strlen($this->entered) >= 4) {
                $this->error = 'That is not the right PIN.';
                $this->entered = '';
            }

            return;
        }

        $this->dispatch('pin-accepted', action: $this->action, subject: $this->subject);

        $this->close();
    }

    /** One person's PIN, or any grown-up's, depending on what was asked. */
    protected function accepts(string $pin): bool
    {
        if ($this->anyAdult) {
            return $this->adults->contains(fn (Member $adult) => $adult->checkPin($pin));
        }

        return $this->member?->checkPin($pin) ?? false;
    }

    public function close(): void
    {
        $this->reset(['memberId', 'anyAdult', 'action', 'subject', 'entered', 'error']);
    }
}; ?>

<div>
    @if ($this->isOpen())
        <div wire:click.self="close" class="modal-backdrop modal-viewport z-[60]" role="dialog" aria-modal="true" aria-label="{{ $this->prompt }}">
            <div class="w-full max-w-xs rounded-3xl bg-white p-5 shadow-2xl dark:bg-slate-900">
                <div class="text-center">
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ $this->prompt }}</p>
                    <h2 class="text-xl font-bold">{{ $this->heading() }}</h2>
                </div>

                <div class="mt-4 flex justify-center gap-3" aria-hidden="true">
                    @for ($i = 0; $i < 4; $i++)
                        <span class="size-4 rounded-full transition-colors
                                     {{ mb_strlen($entered) > $i ? '' : 'bg-slate-200 dark:bg-slate-700' }}"
                              style="{{ mb_strlen($entered) > $i ? 'background-color: '.$this->tint().';' : '' }}"></span>
                    @endfor
                </div>

                @if ($error)
                    <p class="mt-3 text-center text-sm font-medium text-red-600">{{ $error }}</p>
                @endif

                <div class="mt-4 grid grid-cols-3 gap-2">
                    @foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $digit)
                        <button type="button" wire:click="press('{{ $digit }}')"
                                class="grid h-16 place-items-center rounded-2xl bg-slate-100 text-2xl font-bold dark:bg-slate-800">
                            {{ $digit }}
                        </button>
                    @endforeach

                    <button type="button" wire:click="close"
                            class="grid h-16 place-items-center rounded-2xl text-sm font-semibold text-slate-500">Cancel</button>

                    <button type="button" wire:click="press('0')"
                            class="grid h-16 place-items-center rounded-2xl bg-slate-100 text-2xl font-bold dark:bg-slate-800">0</button>

                    <button type="button" wire:click="rub"
                            class="grid h-16 place-items-center rounded-2xl text-slate-500" aria-label="Delete">
                        <svg class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M20 6H9l-5 6 5 6h11a1 1 0 0 0 1-1V7a1 1 0 0 0-1-1zM17 10l-4 4M13 10l4 4" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
