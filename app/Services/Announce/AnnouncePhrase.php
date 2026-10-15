<?php

namespace App\Services\Announce;

use Illuminate\Support\Str;

/**
 * Hearing "announce dinner's ready" in a sentence.
 *
 * The same reasoning as the timer parser: the assistant's read-only promise
 * is its tool surface, so the way to let a wall shout up the stairs without
 * breaking that promise is to recognise the request before the assistant
 * ever sees it.
 *
 * Narrow on purpose. "Announce" and "tell everyone" are unambiguous; almost
 * anything looser would start claiming questions.
 */
class AnnouncePhrase
{
    /** Openings that mean "say this to the house". */
    public const TRIGGERS = [
        'announce ',
        'tell everyone ',
        'tell everybody ',
        'shout ',
        'let everyone know ',
        'let everybody know ',
    ];

    /** Said to a wall, these are questions about announcements. */
    public const QUESTIONS = ['what did', 'who announced', 'did anyone', 'did anybody'];

    public function looksLikeOne(string $said): bool
    {
        $said = mb_strtolower(trim($said));

        foreach (self::QUESTIONS as $question) {
            if (str_contains($said, $question)) {
                return false;
            }
        }

        foreach (self::TRIGGERS as $trigger) {
            if (str_starts_with($said, $trigger)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What to say, with the instruction taken off the front.
     *
     * Null when nothing is left — "announce" on its own is somebody who
     * stopped mid-sentence, and announcing an empty room at the house is
     * worse than admitting it was not understood.
     */
    public function message(string $said): ?string
    {
        $said = trim($said);
        $lower = mb_strtolower($said);

        foreach (self::TRIGGERS as $trigger) {
            if (! str_starts_with($lower, $trigger)) {
                continue;
            }

            $message = trim(mb_substr($said, mb_strlen($trigger)));

            // "announce that dinner's ready" — the "that" is scaffolding.
            // Trimmed again afterwards, and the emptiness checked after the
            // stripping rather than before it: "announce that" on its own
            // was coming back as the word "That".
            $message = trim((string) preg_replace('/^that\b\s*/i', '', $message));

            return $message === '' ? null : Str::ucfirst($message);
        }

        return null;
    }
}
