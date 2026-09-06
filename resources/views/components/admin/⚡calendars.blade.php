<?php

use App\Exceptions\CalDavException;
use App\Jobs\SyncCalendarAccountJob;
use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Household;
use App\Services\CalDav\AccountService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * iCloud account management.
 *
 * Credentials are entered here, never in .env, and several Apple IDs can be
 * connected side by side.
 */
new #[Layout('layouts::app')] class extends Component
{
    public bool $adding = false;

    #[Validate('required|string|max:60')]
    public string $label = '';

    #[Validate('required|email')]
    public string $appleId = '';

    #[Validate('required|string|min:8')]
    public string $appPassword = '';

    public ?string $connectError = null;

    public bool $connecting = false;

    /** Real iCloud accounts — the only kind that can be synced. */
    #[Computed]
    public function accounts(): Collection
    {
        return Household::current()
            ->calendarAccounts()
            ->where('provider', CalendarAccount::PROVIDER_ICLOUD)
            ->with(['calendars' => fn ($q) => $q->orderBy('name'), 'calendars.member'])
            ->orderBy('label')
            ->get();
    }

    /**
     * Seeded demo content, kept separate: it has no credentials, so offering it
     * a "Sync now" button would only produce a confusing failure.
     */
    #[Computed]
    public function demoAccount(): ?CalendarAccount
    {
        return Household::current()
            ->calendarAccounts()
            ->where('provider', 'demo')
            ->withCount('calendars')
            ->first();
    }

    public function removeDemoData(): void
    {
        $this->demoAccount()?->delete();

        unset($this->demoAccount, $this->accounts);

        $this->dispatch('saved', message: 'Demo data removed.');
    }

    #[Computed]
    public function members(): Collection
    {
        return Household::current()->members;
    }

    public function startAdding(): void
    {
        $this->reset(['label', 'appleId', 'appPassword', 'connectError']);
        $this->adding = true;
    }

    public function connect(): void
    {
        $this->validate();
        $this->connectError = null;

        try {
            $account = app(AccountService::class)->connect(
                Household::current(),
                $this->label,
                trim($this->appleId),
                trim($this->appPassword),
            );
        } catch (CalDavException $e) {
            // The password never reaches the session on a failure.
            $this->connectError = $e->getMessage();

            return;
        }

        SyncCalendarAccountJob::dispatch($account);

        $this->reset(['adding', 'label', 'appleId', 'appPassword']);
        unset($this->accounts);

        $this->dispatch('saved', message: 'Connected. Syncing in the background.');
    }

    public function syncNow(int $accountId): void
    {
        $account = $this->findAccount($accountId);

        SyncCalendarAccountJob::dispatch($account);

        $this->dispatch('saved', message: "Syncing {$account->label}…");
    }

    public function forceResync(int $accountId): void
    {
        $account = $this->findAccount($accountId);

        SyncCalendarAccountJob::dispatch($account, force: true);

        $this->dispatch('saved', message: "Full resync of {$account->label} queued.");
    }

    public function disconnect(int $accountId): void
    {
        $account = $this->findAccount($accountId);
        $label = $account->label;

        // Cascades to its calendars and their events.
        $account->delete();

        unset($this->accounts);

        $this->dispatch('saved', message: "Removed {$label}.");
    }

    public function assignMember(int $calendarId, ?string $memberId): void
    {
        $this->findCalendar($calendarId)->update([
            'member_id' => $memberId !== '' && $memberId !== null ? (int) $memberId : null,
        ]);

        unset($this->accounts);
    }

    public function toggleVisible(int $calendarId): void
    {
        $calendar = $this->findCalendar($calendarId);
        $calendar->update(['is_visible' => ! $calendar->is_visible]);

        unset($this->accounts);
    }

    protected function findAccount(int $id): CalendarAccount
    {
        return CalendarAccount::where('household_id', Household::current()->id)->findOrFail($id);
    }

    protected function findCalendar(int $id): Calendar
    {
        return Calendar::whereHas(
            'account',
            fn ($q) => $q->where('household_id', Household::current()->id)
        )->findOrFail($id);
    }
}; ?>

<div class="app-shell flex flex-col">
    <header class="flex shrink-0 items-center gap-3 px-4 pt-4 pb-2">
        <a href="{{ route('admin') }}" wire:navigate
           class="grid touch-target place-items-center rounded-xl bg-white text-slate-500 dark:bg-slate-900" aria-label="Back">
            <svg class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                <path d="m15 18-6-6 6-6" />
            </svg>
        </a>
        <h1 class="text-2xl font-bold">Calendars</h1>
    </header>

    <div class="pane-scroll min-h-0 flex-1 space-y-4 px-4 pb-8">
        <div x-data="{ show: false, message: '' }"
             x-on:saved.window="message = $event.detail.message; show = true; setTimeout(() => show = false, 3000)"
             x-show="show" x-cloak x-transition
             class="fixed inset-x-4 top-4 z-50 rounded-xl bg-slate-900 px-4 py-3 text-white shadow-lg dark:bg-white dark:text-slate-900">
            <span x-text="message"></span>
        </div>

        @forelse ($this->accounts as $account)
            <section class="rounded-2xl bg-white p-4 dark:bg-slate-900" wire:key="account-{{ $account->id }}">
                <div class="flex items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <h2 class="truncate font-semibold">{{ $account->label }}</h2>
                        <p class="truncate text-sm text-slate-500 dark:text-slate-400">{{ $account->appleId() }}</p>
                    </div>

                    @php
                        $badge = match ($account->status) {
                            'ok' => ['Connected', 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300'],
                            'error' => ['Error', 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300'],
                            'disabled' => ['Disabled', 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400'],
                            default => ['Pending', 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300'],
                        };
                    @endphp
                    <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold {{ $badge[1] }}">{{ $badge[0] }}</span>
                </div>

                <dl class="mt-3 space-y-1 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500 dark:text-slate-400">Last sync</dt>
                        <dd>{{ $account->last_synced_at?->diffForHumans() ?? 'never' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500 dark:text-slate-400">Calendars</dt>
                        <dd>{{ $account->calendars->count() }}</dd>
                    </div>
                </dl>

                @if ($account->last_error)
                    <p class="mt-2 rounded-xl bg-red-50 p-3 text-sm text-red-800 dark:bg-red-900/20 dark:text-red-300">
                        {{ $account->last_error }}
                    </p>
                @endif

                {{-- Calendars --}}
                <ul class="mt-3 divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($account->calendars as $calendar)
                        <li class="py-2" wire:key="calendar-{{ $calendar->id }}">
                            <div class="flex items-center gap-3">
                                <span class="size-4 shrink-0 rounded-full" style="background-color: {{ $calendar->colour }};"></span>
                                <span class="min-w-0 flex-1 truncate font-medium">{{ $calendar->name }}</span>
                            </div>

                            {{-- Alpine flips the switch under the finger; Livewire persists it. --}}
                            <div
                                x-data="{ on: @js($calendar->is_visible) }"
                                class="mt-1 flex touch-target items-center justify-between gap-3 pl-7"
                            >
                                <span :id="$id('switch-label')" class="text-sm text-slate-500 dark:text-slate-400">
                                    Show on display
                                </span>

                                <button
                                    type="button"
                                    role="switch"
                                    :aria-checked="on ? 'true' : 'false'"
                                    :aria-labelledby="$id('switch-label')"
                                    x-on:click="on = ! on; $wire.toggleVisible({{ $calendar->id }})"
                                    class="grid touch-target shrink-0 place-items-center"
                                >
                                    {{-- The 44px target is the button; the track is the visible part. --}}
                                    <span
                                        class="relative block h-7 w-12 rounded-full transition-colors duration-150"
                                        :class="on ? 'bg-blue-600' : 'bg-slate-300 dark:bg-slate-600'"
                                    >
                                        <span
                                            class="absolute top-1 left-1 block size-5 rounded-full bg-white shadow transition-transform duration-150"
                                            :class="on && 'translate-x-5'"
                                        ></span>
                                    </span>
                                </button>
                            </div>

                            <label class="mt-1 flex items-center gap-2 pl-7 text-sm">
                                <span class="text-slate-500 dark:text-slate-400">Belongs to</span>
                                <select
                                    wire:change="assignMember({{ $calendar->id }}, $event.target.value)"
                                    class="touch-target min-w-0 flex-1 rounded-lg border border-slate-300 bg-white px-2 text-sm dark:border-slate-700 dark:bg-slate-950"
                                >
                                    <option value="">Nobody in particular</option>
                                    @foreach ($this->members as $member)
                                        <option value="{{ $member->id }}" @selected($calendar->member_id === $member->id)>{{ $member->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" wire:click="syncNow({{ $account->id }})"
                            class="touch-target rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white">Sync now</button>
                    <button type="button" wire:click="forceResync({{ $account->id }})"
                            wire:confirm="Discard sync tokens and re-read everything from iCloud?"
                            class="touch-target rounded-xl bg-slate-100 px-4 text-sm font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">Force resync</button>
                    <button type="button" wire:click="disconnect({{ $account->id }})"
                            wire:confirm="Remove this account? Its calendars and their events are deleted from FamilyHub. Nothing changes in iCloud."
                            class="touch-target rounded-xl px-4 text-sm font-semibold text-red-600">Remove</button>
                </div>
            </section>
        @empty
            <p class="rounded-2xl bg-white p-6 text-center text-slate-500 dark:bg-slate-900 dark:text-slate-400">
                No iCloud accounts yet.
            </p>
        @endforelse

        {{-- Add an account --}}
        @if ($adding)
            <form wire:submit="connect" class="space-y-3 rounded-2xl bg-white p-4 dark:bg-slate-900">
                <h2 class="font-semibold">Add an iCloud account</h2>

                <div>
                    <label class="block text-sm font-medium" for="label">Name it</label>
                    <input wire:model="label" id="label" type="text" placeholder="Simon's iCloud"
                           class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-4 dark:border-slate-700 dark:bg-slate-950">
                    @error('label') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium" for="appleId">Apple ID</label>
                    <input wire:model="appleId" id="appleId" type="email" inputmode="email" autocapitalize="none" autocomplete="off"
                           class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-4 dark:border-slate-700 dark:bg-slate-950">
                    @error('appleId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium" for="appPassword">App-specific password</label>
                    <input wire:model="appPassword" id="appPassword" type="password" autocomplete="off" placeholder="xxxx-xxxx-xxxx-xxxx"
                           class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-4 font-mono dark:border-slate-700 dark:bg-slate-950">
                    @error('appPassword') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        Not your Apple ID password. Create one at appleid.apple.com → Sign-In and Security → App-Specific Passwords.
                    </p>
                </div>

                @if ($connectError)
                    <p class="rounded-xl bg-red-50 p-3 text-sm text-red-800 dark:bg-red-900/20 dark:text-red-300">{{ $connectError }}</p>
                @endif

                <div class="flex gap-2">
                    <button type="submit" class="touch-target flex-1 rounded-xl bg-blue-600 font-semibold text-white">
                        <span wire:loading.remove wire:target="connect">Connect</span>
                        <span wire:loading wire:target="connect">Checking with iCloud…</span>
                    </button>
                    <button type="button" wire:click="$set('adding', false)" class="touch-target rounded-xl px-4 font-semibold text-slate-500">Cancel</button>
                </div>
            </form>
        @else
            <button type="button" wire:click="startAdding"
                    class="touch-target w-full rounded-2xl border-2 border-dashed border-slate-300 font-semibold text-slate-500 dark:border-slate-700 dark:text-slate-400">
                Add an iCloud account
            </button>
        @endif

        @if ($this->demoAccount)
            <section class="rounded-2xl border border-dashed border-slate-300 p-4 dark:border-slate-700">
                <h2 class="font-semibold text-slate-500 dark:text-slate-400">Demo data</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    {{ $this->demoAccount->calendars_count }} seeded calendars, so the wall display has
                    something to show before iCloud is connected. Not a real account. Never synced.
                </p>
                <button type="button" wire:click="removeDemoData"
                        wire:confirm="Remove the seeded demo calendars and their events?"
                        class="touch-target mt-2 rounded-xl px-4 text-sm font-semibold text-red-600">
                    Remove demo data
                </button>
            </section>
        @endif
    </div>
</div>
