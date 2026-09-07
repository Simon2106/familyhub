<?php

namespace App\Services\Points;

use App\Models\Member;
use App\Models\Redemption;
use App\Models\Reward;
use RuntimeException;

/**
 * Asking to spend points, and a grown-up deciding.
 *
 * Two steps rather than one, and each guards something different. The child's
 * PIN on the request stops a sibling spending their savings — a genuine
 * problem in a house with two children and one wall-mounted screen. The
 * parent's decision is the point at which a reward is actually handed over,
 * and points are only taken then: a request that is never granted costs
 * nothing, and a declined one leaves no mark on the balance.
 */
class RewardShop
{
    public function __construct(protected PointsLedger $ledger) {}

    /** @throws RuntimeException when they cannot afford it */
    public function request(Member $member, Reward $reward): Redemption
    {
        $balance = $this->ledger->balanceFor($member);

        if ($balance < $reward->cost) {
            throw new RuntimeException(
                "{$reward->name} costs {$reward->cost} points and you have {$balance}."
            );
        }

        return Redemption::create([
            'household_id' => $member->household_id,
            'member_id' => $member->id,
            'reward_id' => $reward->id,
            // Snapshotted: deleting or re-pricing a reward must not rewrite
            // what a child remembers saving up for.
            'name' => $reward->name,
            'cost' => $reward->cost,
            'status' => 'pending',
            'requested_at' => now(),
        ]);
    }

    /**
     * A grown-up says yes, and only now do the points move.
     *
     * Affordability is checked again here: points can have been spent, or
     * taken back, between the asking and the answering.
     *
     * @throws RuntimeException
     */
    public function grant(Redemption $redemption, ?Member $by = null): Redemption
    {
        if (! $redemption->isPending()) {
            return $redemption;
        }

        $balance = $this->ledger->balanceFor($redemption->member);

        if ($balance < $redemption->cost) {
            throw new RuntimeException(
                "{$redemption->member->name} no longer has enough points for {$redemption->name}."
            );
        }

        $redemption->forceFill([
            'status' => 'granted',
            'decided_at' => now(),
            'decided_by_member_id' => $by?->id,
        ])->save();

        $this->ledger->spend($redemption->member, $redemption, $redemption->cost, $redemption->name);

        return $redemption->fresh();
    }

    /** No, or not yet. Nothing was taken, so nothing is given back. */
    public function decline(Redemption $redemption, ?Member $by = null): Redemption
    {
        if (! $redemption->isPending()) {
            return $redemption;
        }

        $redemption->forceFill([
            'status' => 'declined',
            'decided_at' => now(),
            'decided_by_member_id' => $by?->id,
        ])->save();

        return $redemption->fresh();
    }

    /**
     * Undo a grant, giving the points back.
     *
     * The refund is a reversal beside the spend rather than a deletion, so the
     * child's ledger still reads as an account of what happened.
     */
    public function ungrant(Redemption $redemption): Redemption
    {
        if (! $redemption->isGranted()) {
            return $redemption;
        }

        $this->ledger->refund($redemption->member, $redemption, $redemption->name.' (cancelled)');

        $redemption->forceFill([
            'status' => 'declined',
            'decided_at' => now(),
        ])->save();

        return $redemption->fresh();
    }

    /** What a balance is worth in money, when allowance mode is on. */
    public function allowancePence(Member $member, int $points): int
    {
        return (int) round($points * $member->household->allowancePencePerPoint());
    }
}
