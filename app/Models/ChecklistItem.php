<?php

namespace App\Models;

use App\Casts\CalendarDate;
use App\Observers\ChecklistItemObserver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy(ChecklistItemObserver::class)]
#[Fillable(['checklist_id', 'member_id', 'done_by_member_id', 'event_id', 'mirror_event_id', 'title', 'quantity', 'notes', 'due_on', 'surface_from', 'is_done', 'done_at', 'sort_order'])]
class ChecklistItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_done' => 'boolean',
            'done_at' => 'datetime',
            'due_on' => CalendarDate::class,
            'surface_from' => CalendarDate::class,
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

    /**
     * What the deadline is in aid of — the vaccination the form is for.
     *
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** The all-day reminder mirrored into iCloud, if there is one. */
    /** @return BelongsTo<Event, $this> */
    public function mirrorEvent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'mirror_event_id');
    }

    /**
     * The day this to-do starts being shown.
     *
     * Null for an undated to-do: there is no deadline to count back from, so
     * it is simply always visible.
     */
    public function surfaceFrom(?int $leadDays = null): ?CarbonImmutable
    {
        if ($this->due_on === null) {
            return null;
        }

        if ($this->surface_from !== null) {
            return $this->surface_from->toImmutable()->startOfDay();
        }

        $leadDays ??= $this->checklist->household->todoLeadDays();

        return $this->due_on->toImmutable()->startOfDay()->subDays($leadDays);
    }

    /** Whether it has earned a place on the wall yet. */
    public function isSurfaced(?CarbonImmutable $today = null, ?int $leadDays = null): bool
    {
        $from = $this->surfaceFrom($leadDays);

        if ($from === null) {
            return true;
        }

        // Compared as dates. A due date is a day, not an instant, and London
        // midnight is 23:00 the day before in UTC — enough to make a to-do
        // that has surfaced look as though it has not.
        return $from->toDateString() <= ($today ?? Household::current()->todayLocal())->toDateString();
    }

    /**
     * How close the deadline is, as a word the views can style on.
     *
     * overdue | today | tomorrow | soon | later | none
     */
    public function urgency(?CarbonImmutable $today = null): string
    {
        if ($this->due_on === null) {
            return 'none';
        }

        $days = $this->daysUntilDue($today);

        return match (true) {
            $days < 0 => 'overdue',
            $days === 0 => 'today',
            $days === 1 => 'tomorrow',
            $days <= 3 => 'soon',
            default => 'later',
        };
    }

    /** The badge shown beside the title: "Overdue", "Due tomorrow", "Due in 5 days". */
    public function dueBadge(?CarbonImmutable $today = null): ?string
    {
        if ($this->due_on === null) {
            return null;
        }

        $days = $this->daysUntilDue($today);

        return match (true) {
            $days < 0 => $days === -1 ? 'Overdue by a day' : 'Overdue by '.abs($days).' days',
            $days === 0 => 'Due today',
            $days === 1 => 'Due tomorrow',
            default => 'Due in '.$days.' days',
        };
    }

    /**
     * Whole days from today to the due date, negative once it is past.
     *
     * Both sides are reduced to a plain date first: the two values reach here
     * in different zones — one a UTC-parsed date column, the other household
     * midnight — and subtracting those directly is off by a day for most of
     * the year.
     */
    protected function daysUntilDue(?CarbonImmutable $today = null): int
    {
        $today ??= Household::current()->todayLocal();

        return (int) CarbonImmutable::parse($today->toDateString())
            ->diffInDays(CarbonImmutable::parse($this->due_on->toDateString()), false);
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

    /**
     * Only the to-dos due soon enough to be worth showing.
     *
     * Done in SQL rather than after the fact so the wall does not load six
     * weeks of future paperwork to throw most of it away. Undated items have
     * no deadline to wait for and always pass.
     *
     * @param  Builder<ChecklistItem>  $query
     */
    public function scopeSurfaced(Builder $query, ?CarbonImmutable $today = null, ?int $leadDays = null): void
    {
        $household = Household::current();
        $today ??= $household->todayLocal();
        $leadDays ??= $household->todoLeadDays();

        $query->where(fn (Builder $q) => $q
            ->whereNull('due_on')
            ->orWhere('surface_from', '<=', $today->toDateString())
            ->orWhere(fn (Builder $q) => $q
                ->whereNull('surface_from')
                ->where('due_on', '<=', $today->addDays($leadDays)->toDateString())));
    }

    /** The mirror image of scopeSurfaced: what is being held back. */
    /** @param Builder<ChecklistItem> $query */
    public function scopeUpcoming(Builder $query, ?CarbonImmutable $today = null, ?int $leadDays = null): void
    {
        $household = Household::current();
        $today ??= $household->todayLocal();
        $leadDays ??= $household->todoLeadDays();

        $query->whereNotNull('due_on')
            ->where(fn (Builder $q) => $q
                ->where('surface_from', '>', $today->toDateString())
                ->orWhere(fn (Builder $q) => $q
                    ->whereNull('surface_from')
                    ->where('due_on', '>', $today->addDays($leadDays)->toDateString())));
    }

    public function toggle(?Member $member = null): void
    {
        $this->is_done = ! $this->is_done;
        $this->done_at = $this->is_done ? now() : null;
        $this->done_by_member_id = $this->is_done ? $member?->id : null;
        $this->save();
    }
}
