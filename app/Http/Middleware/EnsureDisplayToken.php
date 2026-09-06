<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the wall display without ever showing it a login form.
 *
 * The iPad is opened at /display?token=..., which mints a year-long encrypted
 * cookie and renders the display in the same response — no redirect, and the
 * token deliberately stays in the URL.
 *
 * That last part is not an oversight. iOS gives a home-screen web app its own
 * cookie jar and its own localStorage, entirely separate from Safari's, so a
 * pairing done in Safari does not carry into the installed PWA. Keeping the
 * token in the URL means "Add to Home Screen" captures it, and the PWA
 * re-pairs itself inside its own storage on first launch. Stripping it would
 * leave the PWA permanently unpaired.
 *
 * The trade-off is that the token sits in the address bar. That is acceptable
 * here: the wall iPad runs in Guided Access with no visible chrome, and the
 * token only ever grants read access to a household calendar.
 */
class EnsureDisplayToken
{
    public const COOKIE = 'fh_display';

    /** A wall display must never quietly log itself out. */
    public const COOKIE_LIFETIME = 60 * 24 * 365;

    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('familyhub.display.token');

        if ($expected === '') {
            abort(503, 'No display token is configured. Run: php artisan familyhub:display-token');
        }

        if ($this->tokenMatches($request->query('token'), $expected)) {
            // Make the pairing visible to this same request, not just the next one.
            $request->cookies->set(self::COOKIE, $expected);

            // Queued rather than attached by hand, so it survives whatever kind
            // of response the component returns.
            Cookie::queue(Cookie::make(self::COOKIE, $expected, minutes: self::COOKIE_LIFETIME));

            return $this->authorised($request, $next, $expected);
        }

        if ($this->tokenMatches($request->cookie(self::COOKIE), $expected)) {
            return $this->authorised($request, $next, $expected);
        }

        // An authenticated parent browsing from a phone can preview the display.
        if ($request->user()) {
            return $this->authorised($request, $next, $expected);
        }

        return response()->view('display-unpaired', status: 403);
    }

    /**
     * Hand the token to the view so the page can mirror it into localStorage.
     *
     * Anyone reaching this point is already authorised to see the display, so
     * this exposes nothing new — and it means a device paired by cookie alone
     * still has a copy to re-pair from later.
     *
     * @param  Closure(Request): Response  $next
     */
    protected function authorised(Request $request, Closure $next, string $token): Response
    {
        $request->attributes->set('display_token', $token);

        return $next($request);
    }

    protected function tokenMatches(mixed $given, string $expected): bool
    {
        return is_string($given) && $given !== '' && hash_equals($expected, $given);
    }
}
