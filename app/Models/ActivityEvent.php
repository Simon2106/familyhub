<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One thing the house did. */
#[Fillable(['household_id', 'entity_id', 'category', 'label', 'from_state', 'to_state', 'at'])]
class ActivityEvent extends Model
{
    use HasFactory;

    /** The categories /admin offers, and what each one means in a sentence. */
    public const CATEGORIES = [
        'people' => 'People',
        'doors' => 'Doors and windows',
        'heating' => 'Heating',
        'lights' => 'Lights and sockets',
        'other' => 'Anything else',
    ];

    /** How long a run of events counts as one thing happening. */
    public const WINDOW_MINUTES = 5;

    /** How long the feed is kept. */
    public const KEEP_DAYS = 30;

    protected function casts(): array
    {
        return ['at' => UtcDateTime::class];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @param Builder<ActivityEvent> $query */
    public function scopeForHousehold(Builder $query, Household $household): void
    {
        $query->where('household_id', $household->id);
    }

    /** @param Builder<ActivityEvent> $query */
    public function scopeSince(Builder $query, CarbonInterface $from): void
    {
        $query->where('at', '>=', $from);
    }

    /** Whether this is the kind of thing quiet hours should swallow. */
    public function isQuietable(): bool
    {
        // A light coming on at two in the morning is noise. Somebody coming
        // home at two in the morning is not.
        return $this->category === 'lights';
    }
}
