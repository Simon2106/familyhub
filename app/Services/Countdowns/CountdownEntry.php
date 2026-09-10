<?php

namespace App\Services\Countdowns;

use App\Models\Countdown;
use App\Models\Member;
use Carbon\CarbonImmutable;

/** One thing being counted down to, and how far off it is. */
class CountdownEntry
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly CarbonImmutable $on,
        public readonly CarbonImmutable $today,
        public readonly ?Countdown $countdown = null,
        public readonly ?Member $member = null,
    ) {}

    public function days(): int
    {
        // Both reduced to a date first: the household's midnight is 23:00 UTC
        // the day before, and a difference in hours would be a day out for
        // half of every year.
        return (int) $this->today->startOfDay()->diffInDays($this->on->startOfDay(), false);
    }

    /**
     * The words, not the number.
     *
     * "12 days until Cornwall" is what somebody says out loud; "12" beside a
     * label is something to be decoded.
     */
    public function sentence(): string
    {
        return match (true) {
            $this->days() === 0 => $this->label.' — today',
            $this->days() === 1 => 'Tomorrow: '.$this->label,
            default => $this->days().' days until '.$this->label,
        };
    }

    public function isToday(): bool
    {
        return $this->days() === 0;
    }

    public function isBirthday(): bool
    {
        return $this->member !== null;
    }

    public function colour(): string
    {
        return $this->member?->colour ?? '#2563eb';
    }
}
