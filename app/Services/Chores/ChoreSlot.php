<?php

namespace App\Services\Chores;

use App\Models\Chore;
use App\Models\ChoreInstance;

/**
 * One chore on one day, whether or not anything has happened to it yet.
 *
 * The wall renders these rather than instances, because a chore nobody has
 * touched has no instance — and "not done yet" is exactly the state a child
 * most needs to see.
 */
class ChoreSlot
{
    public function __construct(
        public readonly Chore $chore,
        public readonly string $date,
        public readonly ?ChoreInstance $instance = null,
    ) {}

    public function isDone(): bool
    {
        return $this->instance?->isDone() ?? false;
    }

    public function isApproved(): bool
    {
        return $this->instance?->isApproved() ?? false;
    }

    public function isAwaitingApproval(): bool
    {
        return $this->instance?->isAwaitingApproval() ?? false;
    }

    public function isEarned(): bool
    {
        return $this->instance?->isEarned() ?? false;
    }

    /** Points this is worth today, from the chore until it is locked in. */
    public function points(): int
    {
        return $this->instance?->points ?? $this->chore->points;
    }

    public function key(): string
    {
        return $this->chore->id.'|'.$this->date;
    }
}
