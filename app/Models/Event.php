<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['calendar_id', 'external_id', 'recurrence_id', 'href', 'etag', 'title', 'start_at', 'end_at', 'all_day', 'location', 'notes', 'rrule', 'source_hash', 'status', 'attribution', 'needs_push', 'pushed_at'])]
class Event extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            // Always stored UTC, whatever zone the source used. See UtcDateTime.
            'start_at' => UtcDateTime::class,
            'end_at' => UtcDateTime::class,
            'all_day' => 'boolean',
            'needs_push' => 'boolean',
            'pushed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // uid_hash backs the unique key; external_id is too long to index directly.
        static::saving(function (Event $event) {
            $event->uid_hash = static::uidHash($event->external_id, $event->recurrence_id);
        });
    }

    public static function uidHash(string $externalId, ?string $recurrenceId = null): string
    {
        return hash('sha256', $externalId.'|'.($recurrenceId ?? ''));
    }

    /** @return BelongsTo<Calendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    /**
     * The members this event concerns.
     *
     * Many-to-many because "SW + JW dentist" belongs to both of them.
     *
     * @return BelongsToMany<Member, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Member::class)->withPivot('reason')->withTimestamps();
    }

    /** True once a person has chosen the members by hand. */
    public function attributionIsManual(): bool
    {
        return $this->attribution === 'manual';
    }

    /**
     * Events that overlap the given window at all, not merely those starting in it —
     * a multi-day or overnight event must still show on each day it touches.
     *
     * @param  Builder<Event>  $query
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->where('start_at', '<', $to)->where('end_at', '>', $from);
    }

    /** @param Builder<Event> $query */
    public function scopeNotCancelled(Builder $query): void
    {
        $query->where('status', '!=', 'cancelled');
    }
}
