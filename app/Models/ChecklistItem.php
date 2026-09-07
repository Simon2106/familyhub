<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['checklist_id', 'member_id', 'done_by_member_id', 'title', 'quantity', 'notes', 'due_on', 'is_done', 'done_at', 'sort_order'])]
class ChecklistItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_done' => 'boolean',
            'done_at' => 'datetime',
            'due_on' => 'date',
        ];
    }

    /** @return BelongsTo<Checklist, $this> */
    public function checklist(): BelongsTo
    {
        return $this->belongsTo(Checklist::class);
    }

    /** Who the to-do is for. */
    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** Who actually ticked it, which is not always who it was for. */
    /** @return BelongsTo<Member, $this> */
    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'done_by_member_id');
    }

    public function isOverdue(?CarbonImmutable $today = null): bool
    {
        if ($this->is_done || $this->due_on === null) {
            return false;
        }

        return $this->due_on->lessThan($today ?? Household::current()->todayLocal());
    }

    public function isDueToday(?CarbonImmutable $today = null): bool
    {
        return $this->due_on?->isSameDay($today ?? Household::current()->todayLocal()) ?? false;
    }

    /**
     * Overdue first, then soonest, then undated.
     *
     * MySQL sorts NULL before everything, so undated items need an explicit
     * bucket rather than a plain "order by due_on".
     *
     * @param  Builder<ChecklistItem>  $query
     */
    public function scopeInDueOrder(Builder $query): void
    {
        $query->orderByRaw('CASE WHEN due_on IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_on')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /** @param Builder<ChecklistItem> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('is_done', false);
    }

    public function toggle(?Member $member = null): void
    {
        $this->is_done = ! $this->is_done;
        $this->done_at = $this->is_done ? now() : null;
        $this->done_by_member_id = $this->is_done ? $member?->id : null;
        $this->save();
    }
}
