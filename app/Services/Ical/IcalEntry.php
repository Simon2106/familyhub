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
        /**
         * The actual moments, where the feed gave any.
         *
         * Added alongside the dates rather than replacing them: bins and term
         * dates only ever wanted "which days", and a council that says the
         * bins go out on Tuesday has no time to give. A fixtures list does,
         * and a 15:30 kick-off shown as an all-day event is no use to anyone.
         */
        public readonly ?CarbonImmutable $startsAt = null,
        public readonly ?CarbonImmutable $endsAt = null,
        public readonly bool $allDay = true,
        public readonly ?string $location = null,
    ) {}

    /** The moment it starts, falling back to the morning of the day. */
    public function startMoment(): CarbonImmutable
    {
        return $this->startsAt ?? $this->startsOn->startOfDay();
    }

    /** The moment it ends. An all-day entry runs to the end of its last day. */
    public function endMoment(): CarbonImmutable
    {
        return $this->endsAt ?? $this->endsOn->endOfDay();
    }

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
