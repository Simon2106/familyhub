<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the wall display without ever showing it a login form.
 *
 * The iPad is opened once at /display?token=..., which mints a year-long
 * encrypted cookie and drops the token from the URL. The blade view also keeps
 * the token in localStorage, so if the cookie is ever lost (Safari clearing
 * site data, a reinstalled PWA) the device can re-authorise itself without
 * anyone climbing up to the wall.
 */
class EnsureDisplayToken
{
    public const COOKIE = 'fh_display';

    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('familyhub.display.token');

        if ($expected === '') {
            abort(503, 'No display token is configured. Run: php artisan familyhub:display-token');
        }

        if ($this->tokenMatches($request->query('token'), $expected)) {
            // Redirect to strip the token from the URL and from Safari's history,
            // so a shoulder-surfed address bar does not leak it.
            return redirect()
                ->to($request->url())
                ->withCookie(Cookie::make(self::COOKIE, $expected, minutes: 60 * 24 * 365));
        }

        if ($this->tokenMatches($request->cookie(self::COOKIE), $expected)) {
            return $next($request);
        }

        // An authenticated parent browsing from a phone can preview the display.
        if ($request->user()) {
            return $next($request);
        }

        return response()->view('display-unpaired', status: 403);
    }

    protected function tokenMatches(mixed $given, string $expected): bool
    {
        return is_string($given) && $given !== '' && hash_equals($expected, $given);
    }
}
