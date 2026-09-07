<?php

namespace App\Services\Routines;

use App\Models\Routine;
use App\Models\RoutineStep;
use Illuminate\Support\Collection;

/** One routine on one day: its steps, and which are ticked. */
class RoutineProgress
{
    /**
     * @param  Collection<int, array{step: RoutineStep, done: bool}>  $steps
     */
    public function __construct(
        public readonly Routine $routine,
        public readonly string $date,
        public readonly Collection $steps,
        public readonly bool $isNow,
    ) {}

    public function done(): int
    {
        return $this->steps->where('done', true)->count();
    }

    public function total(): int
    {
        return $this->steps->count();
    }

    /** The state worth making a fuss of. */
    public function allDone(): bool
    {
        return $this->total() > 0 && $this->done() === $this->total();
    }

    public function summary(): string
    {
        return $this->done().'/'.$this->total();
    }
}
