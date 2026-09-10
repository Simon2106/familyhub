<?php

namespace App\Services\Summary;

use App\Services\Countdowns\CountdownEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The week coming, in the few lines that stop Monday being a surprise.
 *
 * Not next week's calendar. Somebody who wants that opens the calendar; this
 * is the "anything I should know?" answer.
 */
class SummaryAhead
{
    /** Enough to be useful, few enough to still be one screen. */
    public const HIGHLIGHTS = 4;

    /**
     * @param  list<array{when: CarbonImmutable, title: string, all_day: bool}>  $highlights
     * @param  Collection<int, CountdownEntry>  $countdowns
     */
    public function __construct(
        public readonly CarbonImmutable $weekStart,
        public readonly int $eventCount,
        public readonly array $highlights,
        public readonly int $mealsPlanned,
        public readonly int $emptyNights,
        public readonly Collection $countdowns,
    ) {}

    public function isQuiet(): bool
    {
        return $this->eventCount === 0;
    }

    public function mealSentence(): string
    {
        return match (true) {
            $this->emptyNights === 0 => 'Every night planned',
            $this->mealsPlanned === 0 => 'No dinners planned yet',
            default => $this->mealsPlanned.' of 7 dinners planned',
        };
    }
}
