<?php

namespace App\Services\Notifications;

use App\Models\CaptureItem;
use App\Models\Household;
use App\Models\Redemption;
use App\Services\Chores\ChoreBoard;

/**
 * What a grown-up has not dealt with yet.
 *
 * One definition, in one place, because it is now asked for twice: the
 * notification triggers use it to decide whether to send anything, and the
 * bell in the header uses it to decide whether to show a dot. A bell that
 * lit up on a different rule from the notice it leads to would be a bell
 * nobody trusted.
 */
class NeedsAttention
{
    public function __construct(protected ChoreBoard $chores) {}

    /** Things captured from email, a photo or a link, still unchecked. */
    public function review(Household $household): int
    {
        return CaptureItem::query()
            ->whereHas('capture', fn ($q) => $q->where('household_id', $household->id))
            ->pending()
            ->count();
    }

    /** Chores ticked but not signed off, and rewards asked for but not answered. */
    public function approvals(Household $household): int
    {
        return $this->chores->awaitingApproval($household)->count()
            + Redemption::where('household_id', $household->id)->pending()->count();
    }

    public function total(Household $household): int
    {
        return $this->review($household) + $this->approvals($household);
    }

    public function any(Household $household): bool
    {
        return $this->total($household) > 0;
    }
}
