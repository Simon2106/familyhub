<?php

namespace App\Services\Timers;

/**
 * A timer somebody asked for out loud, once it has been understood.
 *
 * `guessed` is the honest bit: when the words carried no duration the parser
 * does not refuse, it falls back to five minutes and says so — and the wall
 * repeats that back, because a timer silently set to the wrong length is
 * worse than no timer at all.
 */
class TimerRequest
{
    public function __construct(
        public readonly int $seconds,
        public readonly ?string $name,
        public readonly bool $guessed,
    ) {}

    public function label(): string
    {
        return $this->name ?? 'Timer';
    }

    /** The duration in the words a person would use. */
    public function spokenDuration(): string
    {
        $minutes = intdiv($this->seconds, 60);
        $seconds = $this->seconds % 60;

        $parts = [];

        if ($hours = intdiv($minutes, 60)) {
            $parts[] = trans_choice('{1}:count hour|[2,*]:count hours', $hours, ['count' => $hours]);
            $minutes %= 60;
        }

        if ($minutes) {
            $parts[] = trans_choice('{1}:count minute|[2,*]:count minutes', $minutes, ['count' => $minutes]);
        }

        if ($seconds) {
            $parts[] = trans_choice('{1}:count second|[2,*]:count seconds', $seconds, ['count' => $seconds]);
        }

        return $parts === [] ? '0 minutes' : implode(' ', $parts);
    }

    /** What the wall says back, which is the only confirmation anybody gets. */
    public function confirmation(): string
    {
        $what = $this->name ? ' for the '.$this->name : '';

        return $this->guessed
            ? "I didn't catch how long, so I've set {$this->spokenDuration()}{$what}."
            : "Right — {$this->spokenDuration()}{$what}.";
    }
}
