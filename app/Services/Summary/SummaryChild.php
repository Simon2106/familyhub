<?php

namespace App\Services\Summary;

use App\Models\Member;

/** One child's week: what they did, and what it came to. */
class SummaryChild
{
    public function __construct(
        public readonly Member $member,
        public readonly int $choresDone,
        /** Done but not yet signed off by a grown-up. */
        public readonly int $choresWaiting,
        public readonly int $earned,
        public readonly int $spent,
        public readonly int $balance,
    ) {}

    public function didNothing(): bool
    {
        return $this->choresDone === 0 && $this->earned === 0 && $this->spent === 0;
    }

    /**
     * The line under the name.
     *
     * Written as a sentence rather than a row of numbers because it is read on
     * a wall from a few feet away, and "4 jobs, 20 stars earned" parses at a
     * glance where a table of four columns does not.
     */
    public function sentence(): string
    {
        if ($this->didNothing()) {
            return 'Nothing this week';
        }

        $parts = [];

        if ($this->choresDone > 0) {
            $parts[] = $this->choresDone.' '.($this->choresDone === 1 ? 'job' : 'jobs');
        }

        if ($this->earned > 0) {
            $parts[] = $this->earned.' earned';
        }

        if ($this->spent > 0) {
            $parts[] = $this->spent.' spent';
        }

        return implode(' · ', $parts);
    }
}
