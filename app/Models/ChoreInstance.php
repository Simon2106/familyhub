<?php

namespace App\Models;

use App\Casts\CalendarDate;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One day's worth of a chore, created the moment anything happens to it. */
#[Fillable([
    'chore_id', 'member_id', 'on', 'completed_at', 'completed_by_member_id',
    'approved_at', 'approved_by_member_id', 'points',
])]
class ChoreInstance extends Model
{
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = ['points' => 0];

    protected function casts(): array
    {
        return [
            'on' => CalendarDate::class,
            'completed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Chore, $this> */
    public function chore(): BelongsTo
    {
        return $this->belongsTo(Chore::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function isDone(): bool
    {
        return $this->completed_at !== null;
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /** Done, but a grown-up has not looked at it yet. */
    public function isAwaitingApproval(): bool
    {
        return $this->isDone() && ! $this->isApproved() && $this->chore->needs_approval;
    }

    /**
     * Whether the points for this have been earned.
     *
     * A chore that wants approval earns nothing until it has it; one that does
     * not is earned the moment it is ticked.
     */
    public function isEarned(): bool
    {
        return $this->isDone() && (! $this->chore->needs_approval || $this->isApproved());
    }

    /** @param Builder<ChoreInstance> $query */
    public function scopeDone(Builder $query): void
    {
        $query->whereNotNull('completed_at');
    }
}
