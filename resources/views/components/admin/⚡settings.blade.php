<?php

use App\Models\Household;
use App\Models\Member;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::app')] class extends Component
{
    public string $householdName = '';

    /** Member being edited, or null when the form is closed. */
    public ?int $editingId = null;

    public string $name = '';

    public string $colour = '#2563eb';

    public bool $isChild = false;

    public string $pin = '';

    public function mount(): void
    {
        $this->householdName = Household::current()->name;
    }

    #[Computed]
    public function members(): Collection
    {
        return Household::current()->members()->get();
    }

    public function saveHousehold(): void
    {
        $this->validate(['householdName' => 'required|string|max:120']);

        Household::current()->update(['name' => $this->householdName]);

        $this->dispatch('saved', message: 'Household saved.');
    }

    public function edit(int $id): void
    {
        $member = Member::findOrFail($id);

        $this->editingId = $member->id;
        $this->name = $member->name;
        $this->colour = $member->colour;
        $this->isChild = $member->is_child;
        $this->pin = '';
    }

    public function addMember(): void
    {
        $this->reset(['editingId', 'name', 'colour', 'isChild', 'pin']);
        $this->editingId = 0; // 0 means "new"
    }

    public function saveMember(): void
    {
        $this->validate([
            'name' => 'required|string|max:60',
            'colour' => 'required|regex:/^#[0-9a-fA-F]{6}$/',
            'pin' => 'nullable|digits_between:4,6',
        ]);

        $member = $this->editingId
            ? Member::findOrFail($this->editingId)
            : new Member(['household_id' => Household::current()->id]);

        $member->fill([
            'household_id' => Household::current()->id,
            'name' => $this->name,
            'colour' => strtolower($this->colour),
            'is_child' => $this->isChild,
        ]);

        // Leaving the pin blank keeps whatever is already set.
        if ($this->pin !== '') {
            $member->pin = $this->pin;
        }

        $member->save();

        $this->reset(['editingId', 'name', 'colour', 'isChild', 'pin']);
        unset($this->members);

        $this->dispatch('saved', message: 'Member saved.');
    }

    public function deleteMember(int $id): void
    {
        Member::where('household_id', Household::current()->id)->findOrFail($id)->delete();

        unset($this->members);
        $this->dispatch('saved', message: 'Member removed.');
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
        <h1 class="text-2xl font-bold">Settings</h1>
    </header>

    <div class="pane-scroll min-h-0 flex-1 space-y-6 px-4 pb-8">

        {{-- Saved toast --}}
        <div x-data="{ show: false, message: '' }"
             x-on:saved.window="message = $event.detail.message; show = true; setTimeout(() => show = false, 2500)"
             x-show="show" x-cloak x-transition
             class="fixed inset-x-4 top-4 z-50 rounded-xl bg-slate-900 px-4 py-3 text-white shadow-lg dark:bg-white dark:text-slate-900">
            <span x-text="message"></span>
        </div>

        {{-- Household --}}
        <section class="rounded-2xl bg-white p-4 dark:bg-slate-900">
            <h2 class="font-semibold">Household</h2>
            <form wire:submit="saveHousehold" class="mt-3 flex gap-2">
                <input wire:model="householdName" type="text" aria-label="Household name"
                       class="touch-target min-w-0 flex-1 rounded-xl border border-slate-300 px-4 dark:border-slate-700 dark:bg-slate-950">
                <button type="submit" class="touch-target shrink-0 rounded-xl bg-blue-600 px-5 font-semibold text-white">Save</button>
            </form>
            @error('householdName') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                Timezone: {{ Household::current()->displayTimezone() }}
                <span class="text-slate-400">(times are stored in UTC)</span>
            </p>
        </section>

        {{-- Members --}}
        <section class="rounded-2xl bg-white p-4 dark:bg-slate-900">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold">Family members</h2>
                <button type="button" wire:click="addMember" class="touch-target rounded-xl px-3 font-semibold text-blue-600 dark:text-blue-400">Add</button>
            </div>

            <ul class="mt-2 divide-y divide-slate-100 dark:divide-slate-800">
                @foreach ($this->members as $member)
                    <li class="flex items-center gap-3 py-2" wire:key="member-{{ $member->id }}">
                        <span class="grid size-10 shrink-0 place-items-center rounded-full text-sm font-bold text-white"
                              style="background-color: {{ $member->colour }};">{{ $member->initials() }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{ $member->name }}</span>
                            <span class="block text-sm text-slate-500 dark:text-slate-400">
                                {{ $member->is_child ? 'Child' : 'Adult' }}@if ($member->is_child && $member->pin) · PIN set @endif
                            </span>
                        </span>
                        <button type="button" wire:click="edit({{ $member->id }})"
                                class="touch-target rounded-xl px-3 text-sm font-semibold text-blue-600 dark:text-blue-400">Edit</button>
                    </li>
                @endforeach
            </ul>

            @if ($editingId !== null)
                <form wire:submit="saveMember" class="mt-4 space-y-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-950">
                    <div>
                        <label class="block text-sm font-medium" for="member-name">Name</label>
                        <input wire:model="name" id="member-name" type="text"
                               class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-4 dark:border-slate-700 dark:bg-slate-900">
                        @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex items-center gap-3">
                        <label class="text-sm font-medium" for="member-colour">Colour</label>
                        <input wire:model="colour" id="member-colour" type="color" class="h-11 w-16 rounded-lg border border-slate-300 dark:border-slate-700">
                        <label class="ml-auto flex touch-target items-center gap-2">
                            <input wire:model="isChild" type="checkbox" class="size-5 rounded">
                            <span class="text-sm font-medium">Child</span>
                        </label>
                    </div>

                    @if ($isChild)
                        <div>
                            <label class="block text-sm font-medium" for="member-pin">PIN (leave blank to keep)</label>
                            <input wire:model="pin" id="member-pin" type="text" inputmode="numeric" autocomplete="off"
                                   class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-4 tracking-widest dark:border-slate-700 dark:bg-slate-900">
                            @error('pin') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <div class="flex gap-2">
                        <button type="submit" class="touch-target flex-1 rounded-xl bg-blue-600 font-semibold text-white">Save</button>
                        <button type="button" wire:click="$set('editingId', null)" class="touch-target rounded-xl px-4 font-semibold text-slate-500">Cancel</button>
                        @if ($editingId)
                            <button type="button" wire:click="deleteMember({{ $editingId }})"
                                    wire:confirm="Remove this member? Their calendars stay, but become unassigned."
                                    class="touch-target rounded-xl px-4 font-semibold text-red-600">Delete</button>
                        @endif
                    </div>
                </form>
            @endif
        </section>

        {{-- Wall display --}}
        <section class="rounded-2xl bg-white p-4 dark:bg-slate-900">
            <h2 class="font-semibold">Wall display</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Pair the iPad by opening the display URL on it once. Get the URL with
                <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">php artisan familyhub:display-token</code>.
            </p>
            <dl class="mt-3 space-y-1 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">Dark mode</dt>
                    <dd class="tabular-nums">{{ config('familyhub.dark_mode.start') }} – {{ config('familyhub.dark_mode.end') }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">Screensaver after</dt>
                    <dd>{{ config('familyhub.screensaver.idle_minutes') ?: 'never' }} @if (config('familyhub.screensaver.idle_minutes')) min @endif</dd>
                </div>
            </dl>
            <a href="{{ route('display') }}" wire:navigate class="mt-3 inline-flex touch-target items-center font-semibold text-blue-600 dark:text-blue-400">
                Preview the wall display
            </a>
        </section>

        {{-- Later phases --}}
        <section class="rounded-2xl border border-dashed border-slate-300 p-4 dark:border-slate-700">
            <h2 class="font-semibold text-slate-500 dark:text-slate-400">Calendar accounts</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Google and iCloud syncing arrives in Phase 2, along with per-account sync status and a force-resync button.
            </p>
        </section>
    </div>
</div>
