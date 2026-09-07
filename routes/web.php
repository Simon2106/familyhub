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

/*
| Postmark inbound email. Verified by a secret in the path or by basic auth —
| Postmark signs nothing, so those are the two options it offers.
|
| CSRF is skipped for this route only (see bootstrap/app.php); it authenticates
| with the shared secret instead.
*/
Route::post('/webhooks/postmark/{token?}', \App\Http\Controllers\Webhooks\PostmarkInboundController::class)
    ->middleware('postmark')
    ->name('webhooks.postmark');

/*
| The deployed build id. The wall display polls this and reloads itself when it
| changes, so a deploy reaches the iPad without anyone touching it.
|
| Deliberately unauthenticated and tiny: it leaks nothing beyond "the app was
| redeployed", and the display must be able to reach it before Livewire boots.
*/
Route::get('/version', function () {
    return response()
        ->json(['version' => \App\Support\BuildVersion::current()])
        ->header('Cache-Control', 'no-store, max-age=0');
})->name('version');

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
        // Sharing a newsletter, photo or link to FamilyHub posts it straight
        // into the capture queue.
        'share_target' => [
            'action' => route('share-target'),
            'method' => 'POST',
            'enctype' => 'multipart/form-data',
            'params' => [
                'title' => 'title',
                'text' => 'text',
                'url' => 'url',
                'files' => [
                    [
                        'name' => 'files',
                        'accept' => ['image/*', 'application/pdf'],
                    ],
                ],
            ],
        ],
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
    Route::livewire('/app/review', 'capture.review-page')->name('review');
    Route::livewire('/app/recipes', 'recipes.page')->name('recipes');
    Route::livewire('/app/meals', 'meals.page')->name('meals');

    /*
    | PWA share target. iOS posts the shared payload here; anything with
    | content becomes a capture and the user lands on the review inbox.
    */
    Route::post('/app/share', \App\Http\Controllers\ShareTargetController::class)->name('share-target');
    Route::livewire('/admin', 'admin.settings')->name('admin');
    Route::livewire('/admin/calendars', 'admin.calendars')->name('admin.calendars');
    Route::livewire('/admin/places', 'admin.places')->name('admin.places');
});
