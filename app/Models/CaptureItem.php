<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing the model found in a capture, awaiting a person's decision.
 */
#[Fillable([
    'capture_id', 'type', 'title', 'start_at', 'end_at', 'all_day', 'location', 'notes',
    'member_hint', 'confidence', 'status', 'member_id', 'calendar_id', 'event_id',
    'checklist_item_id', 'reviewed_at',
])]
class CaptureItem extends Model
{
    use HasFactory;

    public const TYPES = ['event', 'task', 'note'];

    /** At or above this, an item is safe to accept in bulk. */
    public const HIGH_CONFIDENCE = 80;

    protected function casts(): array
    {
        return [
            'start_at' => UtcDateTime::class,
            'end_at' => UtcDateTime::class,
            'all_day' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Capture, $this> */
    public function capture(): BelongsTo
    {
        return $this->belongsTo(Capture::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<Calendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @param Builder<CaptureItem> $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', 'pending');
    }

    /** @param Builder<CaptureItem> $query */
    public function scopeHighConfidence(Builder $query): void
    {
        $query->where('confidence', '>=', self::HIGH_CONFIDENCE);
    }

    public function isHighConfidence(): bool
    {
        return $this->confidence >= self::HIGH_CONFIDENCE;
    }

    /** An item without a start cannot become a calendar event. */
    public function isSchedulable(): bool
    {
        return $this->type === 'event' && $this->start_at !== null;
    }

    public function confidenceLabel(): string
    {
        return match (true) {
            $this->confidence >= self::HIGH_CONFIDENCE => 'Confident',
            $this->confidence >= 50 => 'Fairly sure',
            default => 'Unsure',
        };
    }
}
