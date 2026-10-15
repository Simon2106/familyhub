<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A kitchen timer that outlives the page showing it.
 *
 * The row holds the moment it goes off, never a countdown. A countdown in the
 * database would need something to decrement it; an end time needs nothing,
 * survives a Chromium restart, and is the same number for every screen
 * looking at it.
 */
#[Fillable(['household_id', 'name', 'seconds', 'ends_at', 'remaining_seconds', 'rang_at', 'dismissed_at', 'source'])]
class Timer extends Model
{
    use HasFactory;

    /** Nobody sets a kitchen timer for longer than this. */
    public const MAX_SECONDS = 24 * 60 * 60;

    /** What an unclear request gets, rather than nothing. */
    public const FALLBACK_SECONDS = 5 * 60;

    /** How long a finished timer shouts before it goes quiet of its own accord. */
    public const BANNER_SECONDS = 120;

    protected function casts(): array
    {
        return [
            'ends_at' => UtcDateTime::class,
            'rang_at' => UtcDateTime::class,
            'dismissed_at' => UtcDateTime::class,
            'seconds' => 'integer',
            'remaining_seconds' => 'integer',
        ];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @param Builder<Timer> $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('dismissed_at');
    }

    /** @param Builder<Timer> $query */
    public function scopeForHousehold(Builder $query, Household $household): void
    {
        $query->where('household_id', $household->id);
    }

    public function isPaused(): bool
    {
        return $this->remaining_seconds !== null;
    }

    /**
     * Seconds left, never below zero.
     *
     * Computed rather than stored: two screens asking at the same moment must
     * get the same answer, and nothing should have to tick on the server.
     */
    public function remaining(?CarbonImmutable $now = null): int
    {
        if ($this->isPaused()) {
            return max(0, (int) $this->remaining_seconds);
        }

        $now ??= CarbonImmutable::now();

        return max(0, (int) ceil($now->diffInRealSeconds($this->ends_at, false)));
    }

    public function hasFinished(?CarbonImmutable $now = null): bool
    {
        return ! $this->isPaused() && $this->remaining($now) <= 0;
    }

    /** What to call it when nobody gave it a name. */
    public function label(): string
    {
        return filled($this->name) ? (string) $this->name : 'Timer';
    }

    /* ------------------------------ changing ----------------------------- */

    public function pause(?CarbonImmutable $now = null): void
    {
        if ($this->isPaused() || $this->hasFinished($now)) {
            return;
        }

        $this->forceFill([
            'remaining_seconds' => $this->remaining($now),
            'ends_at' => null,
        ])->save();
    }

    public function resume(?CarbonImmutable $now = null): void
    {
        if (! $this->isPaused()) {
            return;
        }

        $now ??= CarbonImmutable::now();

        $this->forceFill([
            'ends_at' => $now->addSeconds(max(1, (int) $this->remaining_seconds)),
            'remaining_seconds' => null,
        ])->save();
    }

    public function dismiss(): void
    {
        $this->forceFill(['dismissed_at' => CarbonImmutable::now()])->save();
    }

    /**
     * What the browser needs to draw and tick this without asking again.
     *
     * The end time goes down as an epoch in milliseconds: the client works
     * from its own clock between resyncs, and a string it has to parse is a
     * string it can parse differently.
     *
     * @return array<string, mixed>
     */
    public function forScreen(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        return [
            'id' => $this->id,
            'name' => $this->label(),
            'seconds' => (int) $this->seconds,
            'remaining' => $this->remaining($now),
            'endsAt' => $this->ends_at?->getTimestampMs(),
            'paused' => $this->isPaused(),
            'finished' => $this->hasFinished($now),
            'rang' => $this->rang_at !== null,
            'source' => (string) $this->source,
        ];
    }
}
