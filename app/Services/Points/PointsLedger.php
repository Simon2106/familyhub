<?php

namespace App\Services\Points;

use App\Models\ChoreInstance;
use App\Models\Member;
use App\Models\PointEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only thing that writes to the points ledger.
 *
 * Two rules hold everything else up:
 *
 *   1. Rows are appended, never changed or removed. Taking points back writes
 *      a reversal, so a child can always see where their stars went.
 *   2. Every write is expressed as "make the net for this source equal N".
 *      Awarding twice is therefore a no-op rather than a double payment, and
 *      un-ticking then re-ticking a chore settles back on the right number
 *      however many times it happens.
 *
 * The second rule is what makes the ledger safe under two thumbs landing on
 * the same tile on a wall-mounted screen, which is a thing that happens
 * roughly daily in a house with two children.
 */
class PointsLedger
{
    /** Bring a chore instance's net up to what it has earned. */
    public function awardFor(ChoreInstance $instance): void
    {
        if (! $instance->isEarned() || $instance->member_id === null) {
            return;
        }

        $this->settle(
            member: $instance->member,
            source: $instance,
            target: (int) $instance->points,
            kind: 'award',
            reason: $instance->chore->title,
        );
    }

    /** Take back whatever a chore instance is currently worth. */
    public function reverseFor(ChoreInstance $instance): void
    {
        if ($instance->member_id === null) {
            return;
        }

        $this->settle(
            member: $instance->member,
            source: $instance,
            target: 0,
            kind: 'reversal',
            reason: $instance->chore->title,
        );
    }

    /** Points spent on something, as a negative entry against its source. */
    public function spend(Member $member, Model $source, int $points, string $reason): PointEntry
    {
        return $this->record($member, -abs($points), 'redemption', $reason, $source);
    }

    /** A parent giving or taking points by hand, with no source behind it. */
    public function adjust(Member $member, int $points, string $reason): PointEntry
    {
        return $this->record($member, $points, 'adjustment', $reason);
    }

    /** Give back what a refused or cancelled redemption cost. */
    public function refund(Member $member, Model $source, string $reason): void
    {
        $this->settle($member, $source, 0, 'reversal', $reason);
    }

    public function balanceFor(Member $member): int
    {
        return (int) PointEntry::where('member_id', $member->id)->sum('points');
    }

    /**
     * Points earned between two datetimes, for the weekly summary.
     *
     * Awards net of reversals, so a chore ticked and then un-ticked in the
     * same week counts for nothing rather than for both.
     */
    public function earnedBetween(Member $member, string $from, string $to): int
    {
        return (int) PointEntry::where('member_id', $member->id)
            ->whereIn('kind', ['award', 'reversal', 'adjustment'])
            ->whereBetween('created_at', [$from, $to])
            ->sum('points');
    }

    /** What this source has paid out so far, net of anything taken back. */
    public function netFor(Model $source): int
    {
        return (int) PointEntry::where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->sum('points');
    }

    /**
     * Move a source's net to exactly $target by writing the difference.
     *
     * Locked, because the wall and a phone can both be looking at the same
     * chore, and "read the net, then write the difference" is the classic
     * shape of a double payment.
     */
    protected function settle(Member $member, Model $source, int $target, string $kind, string $reason): void
    {
        DB::transaction(function () use ($member, $source, $target, $kind, $reason) {
            $net = (int) PointEntry::where('source_type', $source->getMorphClass())
                ->where('source_id', $source->getKey())
                ->lockForUpdate()
                ->sum('points');

            $difference = $target - $net;

            if ($difference === 0) {
                return;
            }

            $this->record($member, $difference, $kind, $reason, $source);
        });
    }

    protected function record(Member $member, int $points, string $kind, string $reason, ?Model $source = null): PointEntry
    {
        return PointEntry::create([
            'household_id' => $member->household_id,
            'member_id' => $member->id,
            'points' => $points,
            'kind' => $kind,
            'reason' => mb_substr($reason, 0, 250),
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
        ]);
    }
}
