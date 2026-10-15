<?php

namespace App\Services\Timers;

use App\Models\Timer;
use Illuminate\Support\Str;

/**
 * Hearing a timer in a sentence somebody said to a kitchen.
 *
 * Done here rather than by the assistant on purpose. The assistant's
 * read-only promise is its tool surface, not its prompt, and the way to keep
 * that promise while still letting the wall set a timer is to never let the
 * request reach the assistant at all: this recognises the shape, acts, and
 * answers.
 *
 * The grammar is small and deliberately so. "Set a 20 minute pasta timer" and
 * "10 minutes until we leave" cover almost everything anybody says to a
 * kitchen wall, and a parser that tried to cover the rest would start
 * claiming sentences that were questions.
 */
class TimerParser
{
    /** Phrases that mean a timer and nothing else. */
    public const TRIGGERS = [
        'timer', 'set a', 'set an', 'remind me in', 'countdown',
        'minutes until', 'minute until', 'hours until', 'hour until',
    ];

    /** Said to a wall, these are questions about timers rather than new ones. */
    public const QUESTIONS = [
        'how long', 'how much longer', 'what timers', 'any timers',
        'is there a timer', 'cancel', 'stop the', 'how many timers',
    ];

    /** Words that are the sentence's furniture rather than the timer's name. */
    public const FILLER = [
        'set', 'a', 'an', 'the', 'please', 'for', 'me', 'start', 'put', 'on',
        'new', 'another', 'timer', 'countdown', 'minutes', 'minute', 'mins',
        'min', 'hours', 'hour', 'hrs', 'hr', 'seconds', 'second', 'secs', 'sec',
        'and', 'to', 'of', 'in', 'until', 'till', 'go', 'off', 'remind', 'us',
    ];

    /** Spoken numbers, because Whisper writes "twenty" as often as "20". */
    public const WORDS = [
        'half' => 0.5, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4,
        'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9,
        'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'fifteen' => 15,
        'twenty' => 20, 'twenty-five' => 25, 'thirty' => 30, 'forty' => 40,
        'forty-five' => 45, 'fifty' => 50, 'sixty' => 60, 'ninety' => 90,
    ];

    /**
     * Whether this was a request to set one.
     *
     * Conservative by design: anything that might be a question goes to the
     * assistant, because answering a question with a timer is a far worse
     * failure than setting no timer.
     */
    public function looksLikeOne(string $said): bool
    {
        $said = mb_strtolower(trim($said));

        if ($said === '') {
            return false;
        }

        foreach (self::QUESTIONS as $question) {
            if (str_contains($said, $question)) {
                return false;
            }
        }

        foreach (self::TRIGGERS as $trigger) {
            if (str_contains($said, $trigger)) {
                return true;
            }
        }

        return false;
    }

    /** What was asked for. Never null: an unclear duration is still a timer. */
    public function parse(string $said): TimerRequest
    {
        $said = trim($said);
        $seconds = $this->seconds($said);

        return new TimerRequest(
            seconds: $seconds ?? Timer::FALLBACK_SECONDS,
            name: $this->name($said),
            guessed: $seconds === null,
        );
    }

    /**
     * How long, in seconds.
     *
     * Null when the words carried no duration at all, which the caller turns
     * into five minutes and an apology rather than into silence.
     */
    public function seconds(string $said): ?int
    {
        $said = mb_strtolower($said);

        // "an hour and a half", said often enough to be worth knowing.
        if (preg_match('/\b(an?|one)\s+hour\s+and\s+a\s+half\b/', $said)) {
            return 90 * 60;
        }

        if (preg_match('/\bhalf\s+an?\s+hour\b/', $said)) {
            return 30 * 60;
        }

        $total = 0;
        $found = false;

        // Each unit separately, so "1 hour 20 minutes" adds up rather than
        // the first match winning.
        foreach ([
            ['/(\d+(?:\.\d+)?|[a-z-]+)\s*(?:hours|hour|hrs|hr|h)\b/', 3600],
            ['/(\d+(?:\.\d+)?|[a-z-]+)\s*(?:minutes|minute|mins|min|m)\b/', 60],
            ['/(\d+(?:\.\d+)?|[a-z-]+)\s*(?:seconds|second|secs|sec|s)\b/', 1],
        ] as [$pattern, $multiplier]) {
            if (! preg_match($pattern, $said, $match)) {
                continue;
            }

            $amount = $this->amount($match[1]);

            if ($amount === null) {
                continue;
            }

            $total += (int) round($amount * $multiplier);
            $found = true;
        }

        if (! $found || $total <= 0) {
            return null;
        }

        return min($total, Timer::MAX_SECONDS);
    }

    /** A figure or a word, as a number. */
    protected function amount(string $token): ?float
    {
        $token = trim($token);

        if (is_numeric($token)) {
            return (float) $token;
        }

        return self::WORDS[$token] ?? null;
    }

    /**
     * What to call it.
     *
     * Whatever is left once the duration and the sentence's furniture are
     * taken out — "set a 20 minute pasta timer" leaves "pasta", and
     * "10 minutes until we leave" leaves "we leave", which is close enough to
     * what somebody meant to be worth putting on the screen.
     */
    public function name(string $said): ?string
    {
        $said = mb_strtolower($said);

        // The duration first: its digits must not survive into the name.
        $said = preg_replace(
            '/(\d+(?:\.\d+)?|[a-z-]+)\s*(?:hours|hour|hrs|hr|minutes|minute|mins|min|seconds|second|secs|sec)\b/',
            ' ',
            $said,
        ) ?? $said;

        $words = preg_split('/[^a-z0-9\']+/', $said, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $kept = array_values(array_filter(
            $words,
            fn (string $word) => ! in_array($word, self::FILLER, true)
                && ! is_numeric($word)
                && ! array_key_exists($word, self::WORDS),
        ));

        if ($kept === []) {
            return null;
        }

        // Four words is a label; more than that is a sentence, and a sentence
        // does not fit in a strip along the top of a screen.
        return Str::of(implode(' ', array_slice($kept, 0, 4)))->trim()->ucfirst()->value() ?: null;
    }
}
