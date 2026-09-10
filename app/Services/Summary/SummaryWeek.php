<?php

namespace App\Services\Summary;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** A week, looked back on. */
class SummaryWeek
{
    /**
     * @param  Collection<int, SummaryChild>  $children
     * @param  Collection<int, SummaryMeal>  $meals
     */
    public function __construct(
        public readonly CarbonImmutable $weekStart,
        public readonly CarbonImmutable $weekEnd,
        public readonly Collection $children,
        public readonly Collection $meals,
        public readonly SummaryAhead $ahead,
    ) {}

    /** "1–7 September", or "28 September – 4 October" when it straddles one. */
    public function heading(): string
    {
        return $this->weekStart->format('M') === $this->weekEnd->format('M')
            ? $this->weekStart->format('j').'–'.$this->weekEnd->format('j F')
            : $this->weekStart->format('j M').' – '.$this->weekEnd->format('j M');
    }

    public function choresDone(): int
    {
        return (int) $this->children->sum(fn (SummaryChild $child) => $child->choresDone);
    }

    public function starsEarned(): int
    {
        return (int) $this->children->sum(fn (SummaryChild $child) => $child->earned);
    }

    public function mealsEaten(): int
    {
        return $this->meals->count();
    }

    /**
     * Whether there is anything here worth putting in front of anybody.
     *
     * A week where nothing was ticked, nothing was eaten and nothing is on is
     * a week the household was away. Sending a notification about it is worse
     * than saying nothing.
     */
    public function isEmpty(): bool
    {
        return $this->choresDone() === 0
            && $this->starsEarned() === 0
            && $this->mealsEaten() === 0
            && $this->ahead->isQuiet();
    }

    /** The whole week in one line, for a push notification. */
    public function line(): string
    {
        $parts = array_filter([
            $this->choresDone() > 0 ? $this->choresDone().' jobs done' : null,
            $this->starsEarned() > 0 ? $this->starsEarned().' stars earned' : null,
            $this->mealsEaten() > 0 ? $this->mealsEaten().' dinners' : null,
            $this->ahead->eventCount > 0 ? $this->ahead->eventCount.' on next week' : null,
        ]);

        return $parts === [] ? 'A quiet week.' : implode(' · ', $parts);
    }
}
