<?php

namespace App\Services\Summary;

use Carbon\CarbonImmutable;

/** One dinner, and what anybody said about it that week. */
class SummaryMeal
{
    public function __construct(
        public readonly CarbonImmutable $on,
        public readonly string $title,
        /** The grown-ups' average, or null if nobody said. */
        public readonly ?float $stars = null,
        public readonly int $thumbsUp = 0,
        public readonly int $thumbsDown = 0,
    ) {}

    public function rated(): bool
    {
        return $this->stars !== null || $this->thumbsUp > 0 || $this->thumbsDown > 0;
    }

    /** How it went, in as few words as it takes. */
    public function verdict(): ?string
    {
        if (! $this->rated()) {
            return null;
        }

        $parts = [];

        if ($this->stars !== null) {
            $parts[] = $this->stars.'/5';
        }

        // The children's half is a count, not an average: two thumbs up and
        // one down is a real thing that happened, and 0.3 is not.
        if ($this->thumbsUp > 0) {
            $parts[] = $this->thumbsUp.' liked it';
        }

        if ($this->thumbsDown > 0) {
            $parts[] = $this->thumbsDown.' did not';
        }

        return implode(', ', $parts);
    }
}
