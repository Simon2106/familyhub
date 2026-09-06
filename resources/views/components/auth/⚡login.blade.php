<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * The only auth surface in FamilyHub. There is no registration route by design:
 * logins are seeded from .env and managed in /admin.
 */
new #[Layout('layouts::app')] class extends Component
{
    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = true;

    public function login(): void
    {
        $this->validate();
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        session()->regenerate();

        $this->redirectIntended(route('app', absolute: false), navigate: true);
    }

    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), maxAttempts: 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }
}; ?>

<div class="app-shell grid place-items-center px-6">
    <div class="w-full max-w-sm">
        <div class="mb-8 text-center">
            <img src="{{ asset('icons/icon-192.png') }}" alt="" class="mx-auto size-16 rounded-2xl">
            <h1 class="mt-4 text-2xl font-semibold">{{ config('app.name') }}</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Sign in to your household</p>
        </div>

        <form wire:submit="login" class="space-y-4">
            <div>
                <label for="email" class="block text-sm font-medium">Email</label>
                <input
                    wire:model="email"
                    id="email"
                    type="email"
                    inputmode="email"
                    autocomplete="username"
                    autocapitalize="none"
                    autofocus
                    class="touch-target mt-1 w-full rounded-xl border border-slate-300 bg-white px-4 text-base dark:border-slate-700 dark:bg-slate-900"
                >
                @error('email')
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="block text-sm font-medium">Password</label>
                <input
                    wire:model="password"
                    id="password"
                    type="password"
                    autocomplete="current-password"
                    class="touch-target mt-1 w-full rounded-xl border border-slate-300 bg-white px-4 text-base dark:border-slate-700 dark:bg-slate-900"
                >
                @error('password')
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <label class="flex touch-target items-center gap-3">
                <input wire:model="remember" type="checkbox" class="size-5 rounded border-slate-300 dark:border-slate-700">
                <span class="text-sm">Keep me signed in</span>
            </label>

            <button
                type="submit"
                class="touch-target w-full rounded-xl bg-blue-600 px-4 font-semibold text-white active:bg-blue-700"
            >
                <span wire:loading.remove wire:target="login">Sign in</span>
                <span wire:loading wire:target="login">Signing in…</span>
            </button>
        </form>
    </div>
</div>
