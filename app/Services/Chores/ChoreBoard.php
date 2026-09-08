<?php

namespace App\Services\Chores;

use App\Models\Chore;
use App\Models\ChoreInstance;
use App\Models\Household;
use App\Models\Member;
use App\Services\Points\PointsLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What is due, and what happens when somebody ticks it.
 *
 * Instances are created on demand rather than swept into existence nightly.
 * Nothing is written by looking at the board, so a fortnight away leaves no
 * trail of accusing empty rows, and there is no cron job standing between a
 * child and their chore list.
 */
class ChoreBoard
{
    public function __construct(protected PointsLedger $ledger) {}

    /**
     * Every chore due on a day, with whatever has happened to it.
     *
     * @param  Collection<int, Chore>|null  $chores  pre-loaded, to keep a week
     *                                               of columns to one query
     * @return Collection<int, ChoreSlot>
     */
    public function forDay(Household $household, CarbonImmutable $date, ?Collection $chores = null, ?Collection $instances = null): Collection
    {
        $chores ??= $this->chores($household);
        $instances ??= $this->instances($household, $date, $date);

        return $chores
            ->filter(fn (Chore $chore) => $chore->occursOn($date))
            ->map(fn (Chore $chore) => new ChoreSlot(
                chore: $chore,
                date: $date->toDateString(),
                instance: $instances->get($chore->id.'|'.$date->toDateString()),
            ))
            ->values();
    }

    /**
     * The same, for a range, keyed by date then member.
     *
     * Two queries for a whole week of wall columns, however many children and
     * chores there are.
     *
     * @return Collection<string, Collection<int|string, Collection<int, ChoreSlot>>>
     */
    public function forRange(Household $household, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $chores = $this->chores($household);
        $instances = $this->instances($household, $from, $to);
        $days = collect();

        for ($date = $from; ! $date->greaterThan($to); $date = $date->addDay()) {
            $days->put(
                $date->toDateString(),
                $this->forDay($household, $date, $chores, $instances)
                    ->groupBy(fn (ChoreSlot $slot) => $slot->chore->member_id ?? 'household'),
            );
        }

        return $days;
    }

    /**
     * Tick a chore off.
     *
     * Idempotent: two thumbs on the same tile produce one instance and one
     * award, because the instance is unique per chore per day and the ledger
     * refuses to pay twice for the same one.
     */
    public function complete(Chore $chore, CarbonImmutable $date, ?Member $by = null): ChoreInstance
    {
        $instance = $this->instanceFor($chore, $date);

        if (! $instance->isDone()) {
            $instance->forceFill([
                'completed_at' => now(),
                'completed_by_member_id' => $by?->id,
                // Locked in at today's value, so changing what a chore is
                // worth never restates what was already earned.
                'points' => $chore->points,
            ])->save();
        }

        if (! $chore->needs_approval) {
            $this->ledger->awardFor($instance);
        }

        return $instance->fresh();
    }

    /** Un-tick. Any points already awarded are reversed, never deleted. */
    public function uncomplete(ChoreInstance $instance): ChoreInstance
    {
        $this->ledger->reverseFor($instance);

        $instance->forceFill([
            'completed_at' => null,
            'completed_by_member_id' => null,
            'approved_at' => null,
            'approved_by_member_id' => null,
        ])->save();

        return $instance->fresh();
    }

    /** A grown-up signs it off, which is when a chore that wanted approval pays. */
    public function approve(ChoreInstance $instance, ?Member $by = null): ChoreInstance
    {
        if (! $instance->isDone()) {
            // Approving something nobody has done means they did it and
            // nobody tapped; record both rather than an odd half-state.
            $instance->forceFill([
                'completed_at' => now(),
                'points' => $instance->chore->points,
            ])->save();
        }

        if (! $instance->isApproved()) {
            $instance->forceFill([
                'approved_at' => now(),
                'approved_by_member_id' => $by?->id,
            ])->save();
        }

        $this->ledger->awardFor($instance->fresh());

        return $instance->fresh();
    }

    /** Withdraw approval without denying it was done. */
    public function unapprove(ChoreInstance $instance): ChoreInstance
    {
        $this->ledger->reverseFor($instance);

        $instance->forceFill(['approved_at' => null, 'approved_by_member_id' => null])->save();

        return $instance->fresh();
    }

    /**
     * Everything done but not yet signed off, whatever day it was done on.
     *
     * Deliberately not limited to today. A chore ticked on Sunday evening
     * needs approving on Monday, and an approval queue that only looks at
     * today strands it silently — the child sees "waiting to be checked"
     * forever and nobody is ever shown the thing to check.
     *
     * @return Collection<int, ChoreInstance>
     */
    public function awaitingApproval(Household $household, int $days = 30): Collection
    {
        return ChoreInstance::query()
            ->whereNotNull('completed_at')
            ->whereNull('approved_at')
            ->where('on', '>=', $household->todayLocal()->subDays($days)->toDateString())
            ->whereHas('chore', fn ($q) => $q
                ->where('household_id', $household->id)
                ->where('needs_approval', true))
            ->with(['chore.member', 'member'])
            // Oldest first: the thing that has been waiting longest is the one
            // most likely to have been forgotten about.
            ->orderBy('on')
            ->orderBy('id')
            ->get();
    }

    /**
     * Points earned but not yet released, because nobody has checked them.
     *
     * The number that explains a child having done things and saved nothing.
     */
    public function pendingPoints(Member $member, string $from, string $to): int
    {
        return (int) ChoreInstance::query()
            ->where('member_id', $member->id)
            ->whereNotNull('completed_at')
            ->whereNull('approved_at')
            ->whereBetween('on', [$from, $to])
            ->whereHas('chore', fn ($q) => $q->where('needs_approval', true))
            ->sum('points');
    }

    /** The row for one chore on one day, made only when something happens. */
    public function instanceFor(Chore $chore, CarbonImmutable $date): ChoreInstance
    {
        return ChoreInstance::firstOrCreate(
            ['chore_id' => $chore->id, 'on' => $date->toDateString()],
            ['member_id' => $chore->member_id, 'points' => $chore->points],
        );
    }

    /** @return Collection<int, Chore> */
    protected function chores(Household $household): Collection
    {
        return Chore::query()
            ->where('household_id', $household->id)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<string, ChoreInstance> keyed "choreId|date" */
    protected function instances(Household $household, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return ChoreInstance::query()
            ->whereHas('chore', fn ($q) => $q->where('household_id', $household->id))
            ->whereBetween('on', [$from->toDateString(), $to->toDateString()])
            ->with('chore')
            ->get()
            ->keyBy(fn (ChoreInstance $i) => $i->chore_id.'|'.$i->on->toDateString());
    }
}
