<?php

namespace App\Services\Routines;

use App\Models\Household;
use App\Models\Member;
use App\Models\Routine;
use App\Models\RoutineStep;
use App\Models\RoutineStepCompletion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What a child's routines look like right now.
 *
 * Like chores, nothing is written by looking: an untouched routine has no
 * rows, and a routine resets simply by the date changing.
 */
class RoutineBoard
{
    /**
     * Every routine belonging to a child on a day, in the order they happen.
     *
     * @return Collection<int, RoutineProgress>
     */
    public function forMember(Member $member, CarbonImmutable $date, ?CarbonImmutable $now = null): Collection
    {
        $now ??= $member->household->nowLocal();

        $routines = Routine::query()
            ->where('member_id', $member->id)
            ->active()
            ->with('steps')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $done = $this->completedStepIds($routines, $date);

        return $routines->map(fn (Routine $routine) => new RoutineProgress(
            routine: $routine,
            date: $date->toDateString(),
            steps: $routine->steps->map(fn (RoutineStep $step) => [
                'step' => $step,
                'done' => $done->contains($step->id),
            ])->values(),
            // "Right now" only means anything on the day it is now.
            isNow: $date->isSameDay($now) && $routine->isActiveAt($now),
        ));
    }

    /** The one to lead with, if any. */
    public function activeFor(Member $member, CarbonImmutable $date, ?CarbonImmutable $now = null): ?RoutineProgress
    {
        return $this->forMember($member, $date, $now)->first(fn (RoutineProgress $p) => $p->isNow);
    }

    /** Idempotent, like every other tick in this app. */
    public function complete(RoutineStep $step, CarbonImmutable $date): void
    {
        RoutineStepCompletion::firstOrCreate(
            ['routine_step_id' => $step->id, 'on' => $date->toDateString()],
            ['completed_at' => now()],
        );
    }

    public function uncomplete(RoutineStep $step, CarbonImmutable $date): void
    {
        RoutineStepCompletion::where('routine_step_id', $step->id)
            ->where('on', $date->toDateString())
            ->delete();
    }

    public function isComplete(RoutineStep $step, CarbonImmutable $date): bool
    {
        return RoutineStepCompletion::where('routine_step_id', $step->id)
            ->where('on', $date->toDateString())
            ->exists();
    }

    /**
     * Which of a household's children have a routine running right now.
     *
     * @return Collection<int, RoutineProgress>
     */
    public function activeAcross(Household $household, CarbonImmutable $date, ?CarbonImmutable $now = null): Collection
    {
        return $household->members()->children()->get()
            ->map(fn (Member $member) => $this->activeFor($member, $date, $now))
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, Routine>  $routines
     * @return Collection<int, int> step ids ticked on that date
     */
    protected function completedStepIds(Collection $routines, CarbonImmutable $date): Collection
    {
        $stepIds = $routines->flatMap(fn (Routine $r) => $r->steps->pluck('id'))->all();

        if ($stepIds === []) {
            return collect();
        }

        return RoutineStepCompletion::whereIn('routine_step_id', $stepIds)
            ->where('on', $date->toDateString())
            ->pluck('routine_step_id');
    }
}
