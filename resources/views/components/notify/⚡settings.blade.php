<?php

use App\Models\Household;
use App\Models\PushSubscription;
use App\Services\Notifications\NotificationSettings;
use App\Services\Notifications\Notifier;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * What this adult wants to be told, and on which devices.
 *
 * Per person, because "tell me about the review inbox" is a thing somebody
 * means everywhere; the devices are listed separately because a subscription
 * belongs to a browser and the same person has several.
 */
new #[Layout('layouts::app')] class extends Component
{
    /** @var array<string, bool> */
    public array $triggers = [];

    public int $lead = NotificationSettings::DEFAULT_LEAD;

    public function mount(): void
    {
        $settings = app(NotificationSettings::class);
        $user = auth()->user();

        foreach (array_keys(NotificationSettings::TRIGGERS) as $trigger) {
            $this->triggers[$trigger] = $user ? $settings->wants($user, $trigger) : false;
        }

        $this->lead = $user ? $settings->leadMinutes($user) : NotificationSettings::DEFAULT_LEAD;
    }

    public function household(): Household
    {
        return Household::current();
    }

    #[Computed]
    public function configured(): bool
    {
        return app(Notifier::class)->isConfigured();
    }

    /** @return Collection<int, PushSubscription> */
    #[Computed]
    public function devices(): Collection
    {
        return auth()->user()?->pushSubscriptions()->latest()->get() ?? collect();
    }

    public function save(): void
    {
        if (! auth()->user()) {
            return;
        }

        app(NotificationSettings::class)->put(auth()->user(), $this->triggers, $this->lead);

        $this->dispatch('saved', message: 'Saved.');
    }

    public function forgetDevice(int $id): void
    {
        auth()->user()?->pushSubscriptions()->whereKey($id)->delete();

        unset($this->devices);
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
        <h1 class="flex-1 text-2xl font-bold">Notifications</h1>
    </header>

    <div x-data="{ show: false, message: '' }"
         x-on:saved.window="message = $event.detail.message; show = true; setTimeout(() => show = false, 2500)"
         x-show="show" x-cloak x-transition
         class="fixed inset-x-4 top-4 z-50 rounded-xl bg-slate-900 px-4 py-3 text-white shadow-lg dark:bg-white dark:text-slate-900">
        <span x-text="message"></span>
    </div>

    <div class="pane-scroll min-h-0 flex-1 space-y-4 px-4 pb-8">
        @unless ($this->configured)
            <p class="rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
                No VAPID keys are set, so nothing can be pushed yet. Generate a pair with
                <code class="rounded bg-black/10 px-1">php artisan familyhub:push-keys</code>
                and add them to <code class="rounded bg-black/10 px-1">.env</code>.
                Everything below still records what it would have told you.
            </p>
        @endunless

        {{-- This device. Subscribing is a browser permission, so it has to be
             asked for from the browser rather than switched on server-side. --}}
        <section class="rounded-2xl bg-white p-4 dark:bg-slate-900"
                 x-data="pushSetup(@js(config('familyhub.push.public_key')), @js(route('push.subscribe')), @js(route('push.unsubscribe')), @js(route('push.test')))">
            <h2 class="font-semibold">This device</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400" x-text="explanation"></p>

            {{-- The browser's own answer, said out loud. "Nothing arrived" has
                 half a dozen causes and this is the one the page can see. --}}
            <div class="mt-3 flex items-center gap-2" x-show="supported" x-cloak>
                <span class="text-sm text-slate-500 dark:text-slate-400">Permission</span>
                <span class="rounded-lg px-2 py-1 text-sm font-semibold"
                      :class="{
                          'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300': permission === 'granted',
                          'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300': permission === 'denied',
                          'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300': permission === 'default',
                      }"
                      x-text="permissionLabel"></span>
            </div>

            <p x-show="supported && permissionDetail" x-cloak
               class="mt-2 text-sm text-slate-500 dark:text-slate-400" x-text="permissionDetail"></p>

            <div class="mt-3 flex flex-wrap gap-2" x-show="supported" x-cloak>
                <button type="button" x-on:click="toggle()" :disabled="busy || permission === 'denied'"
                        class="touch-target rounded-xl px-4 font-semibold text-white disabled:opacity-40"
                        :class="subscribed ? 'bg-slate-500' : 'bg-blue-600'"
                        x-text="subscribed ? 'Stop notifications here' : 'Turn on notifications here'"></button>

                <button type="button" x-on:click="test()" x-show="subscribed" :disabled="busy"
                        class="touch-target rounded-xl bg-slate-100 px-4 font-semibold disabled:opacity-40 dark:bg-slate-800">
                    Send me a test notification
                </button>
            </div>

            <p x-show="result" x-cloak class="mt-2 text-sm font-medium" x-text="result"></p>

            <p x-show="! supported" x-cloak class="mt-3 text-sm text-slate-400" x-text="explanation"></p>
        </section>

        {{-- What to be told about. --}}
        <section class="rounded-2xl bg-white p-4 dark:bg-slate-900">
            <h2 class="font-semibold">Tell me about</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                All off to begin with. Nothing here is urgent enough to be switched on for you.
            </p>

            <div class="mt-3 space-y-1">
                @foreach (NotificationSettings::TRIGGERS as $key => $label)
                    <label class="flex touch-target items-center justify-between gap-3">
                        <span class="min-w-0 text-sm font-medium">{{ $label }}</span>
                        <input wire:model="triggers.{{ $key }}" type="checkbox" class="size-6 shrink-0 rounded">
                    </label>

                    @if ($key === 'event_reminder' && ($triggers['event_reminder'] ?? false))
                        <div class="pb-2 pl-1">
                            <select wire:model="lead"
                                    class="touch-target w-full rounded-xl border border-slate-300 px-3 text-sm dark:border-slate-700 dark:bg-slate-950">
                                @foreach (NotificationSettings::LEAD_TIMES as $minutes => $when)
                                    <option value="{{ $minutes }}">{{ $when }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                @endforeach
            </div>

            <button type="button" wire:click="save"
                    class="mt-3 touch-target w-full rounded-xl bg-blue-600 font-semibold text-white">Save</button>

            <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">
                Nothing is sent between {{ $this->household()->darkMode()['start'] }} and
                {{ $this->household()->darkMode()['end'] }} — the hours the wall dims itself. Anything
                that happens then waits for the morning.
            </p>
        </section>

        {{-- Every device signed up. --}}
        <section class="rounded-2xl bg-white p-4 dark:bg-slate-900">
            <h2 class="font-semibold">Devices</h2>

            @forelse ($this->devices as $device)
                <div class="mt-2 flex items-center gap-3" wire:key="device-{{ $device->id }}">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium">{{ $device->device_label ?: 'A browser' }}</span>
                        <span class="block text-xs text-slate-400">
                            Added {{ $device->created_at->diffForHumans() }}@if ($device->last_sent_at) · last used {{ $device->last_sent_at->diffForHumans() }} @endif
                        </span>
                    </span>
                    <button type="button" wire:click="forgetDevice({{ $device->id }})"
                            class="touch-target rounded-xl px-3 text-sm font-semibold text-red-600">Forget</button>
                </div>
            @empty
                <p class="mt-2 text-sm text-slate-400">No devices yet.</p>
            @endforelse
        </section>
    </div>
</div>
