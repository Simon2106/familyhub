<?php

use App\Http\Controllers\Auth\LogoutController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
| FamilyHub has three front doors:
|   /display  the wall-mounted iPad — token-gated, never shows a login form
|   /app      family phones — authenticated
|   /admin    settings — authenticated
*/

/* --- PWA ------------------------------------------------------------------ */

Route::get('/manifest.webmanifest', function () {
    return response()->json([
        'name' => config('app.name'),
        'short_name' => config('app.name'),
        'description' => 'Family calendar, chores, meals and lists.',
        'start_url' => '/app',
        'scope' => '/',
        'display' => 'standalone',
        'orientation' => 'any',
        'background_color' => '#0f172a',
        'theme_color' => '#0f172a',
        'icons' => [
            ['src' => asset('icons/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => asset('icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => asset('icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
        // Phase 3 adds a share_target here so newsletters can be shared straight
        // into the capture queue from iOS.
    ])->withHeaders(['Content-Type' => 'application/manifest+json']);
})->name('pwa.manifest');

Route::get('/', function () {
    return Auth::check() ? redirect()->route('app') : redirect()->route('login');
});

/*
| The wall display gets its own manifest: the installed PWA must relaunch at a
| URL carrying the pairing token, because iOS scopes cookies per context and a
| start_url of plain /display would open unpaired. Gated by the same middleware,
| so the token is never served to anyone who does not already have it.
*/
Route::get('/display/manifest.webmanifest', function () {
    $token = request()->attributes->get('display_token');

    return response()->json([
        'name' => config('app.name').' Display',
        'short_name' => config('app.name'),
        'description' => 'The family wall calendar.',
        'start_url' => route('display', $token ? ['token' => $token] : []),
        'scope' => '/display',
        'display' => 'standalone',
        'orientation' => 'any',
        'background_color' => '#0f172a',
        'theme_color' => '#0f172a',
        'icons' => [
            ['src' => asset('icons/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => asset('icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => asset('icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
    ])->withHeaders(['Content-Type' => 'application/manifest+json']);
})->middleware('display.token')->name('pwa.manifest.display');

Route::livewire('/display', 'display.wall')
    ->middleware('display.token')
    ->name('display');

Route::middleware('guest')->group(function () {
    Route::livewire('/login', 'auth.login')->name('login');
});

Route::post('/logout', LogoutController::class)
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->group(function () {
    Route::livewire('/app', 'phone.home')->name('app');
    Route::livewire('/admin', 'admin.settings')->name('admin');
});
