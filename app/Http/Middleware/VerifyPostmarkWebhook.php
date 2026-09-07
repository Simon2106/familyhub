<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies an inbound Postmark request.
 *
 * Postmark signs nothing, so the two options it does offer are a secret in the
 * URL and HTTP basic auth. Both are accepted; whichever is configured is used.
 */
class VerifyPostmarkWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('familyhub.postmark.inbound_secret');

        if ($expected === '') {
            abort(503, 'No POSTMARK_INBOUND_SECRET is configured.');
        }

        // Middleware parameters are static strings, so the secret is read from
        // the route parameter rather than passed in.
        $token = $request->route('token');

        if ($this->matches(is_string($token) ? $token : null, $expected)
            || $this->matches($request->getPassword(), $expected)) {
            return $next($request);
        }

        // 404 rather than 401: an unauthenticated caller learns nothing about
        // whether this endpoint exists.
        abort(404);
    }

    protected function matches(?string $given, string $expected): bool
    {
        return is_string($given) && $given !== '' && hash_equals($expected, $given);
    }
}
