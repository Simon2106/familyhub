<?php

namespace App\Support;

/**
 * Whether the person acting has already proved they are a grown-up.
 *
 * One rule, asked from both places rather than decided twice. A parent signed
 * into their own phone has proved it by signing in; the wall in the kitchen has
 * no session and is used by children all day, so there it has to be asked.
 *
 * This is why the check is on the session rather than on which component is
 * rendering: a parent previewing /display from their phone is still a parent,
 * and a shared screen is still shared whatever it happens to be showing.
 */
class AdultGate
{
    public static function isTrusted(): bool
    {
        return auth()->check();
    }
}
