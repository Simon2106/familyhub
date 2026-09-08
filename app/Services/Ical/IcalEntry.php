<?php

namespace App\Services\Ical;

use Carbon\CarbonImmutable;

/** One dated thing from a subscribed calendar. */
class IcalEntry
{
    public function __construct(
        public readonly string $uid,
        public readonly string $summary,
        public readonly CarbonImmutable $startsOn,
        public readonly CarbonImmutable $endsOn,
        public readonly ?string $description = null,
    ) {}

    /** Whether a given day falls inside this entry, end inclusive. */
    public function covers(CarbonImmutable $date): bool
    {
        $day = $date->toDateString();

        return $day >= $this->startsOn->toDateString() && $day <= $this->endsOn->toDateString();
    }

    public function days(): int
    {
        return (int) $this->startsOn->diffInDays($this->endsOn) + 1;
    }
}
