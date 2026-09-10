<?php

namespace App\Models;

use App\Casts\CalendarDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something stuck to the fridge door.
 *
 * Deliberately not an event, a to-do or a chore. "Back late Tuesday" has no
 * time, nothing to tick and nobody it is assigned to, and the honest place for
 * it is a scrap of paper — so this is a scrap of paper.
 */
#[Fillable(['household_id', 'member_id', 'body', 'expires_on', 'sort_order'])]
class Note extends Model
{
    /** As many as fit on a wall before it stops being glanceable. */
    public const ON_THE_WALL = 6;

    /** How long a note with no date given lasts. Long enough; not forever. */
    public const DEFAULT_DAYS = 7;

    protected function casts(): array
    {
        // CalendarDate, not date: Eloquent's date cast writes a time with it,
        // MySQL truncates and SQLite does not, and a note that expires a day
        // early on one database is worse than one that never expires.
        return ['expires_on' => CalendarDate::class];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** Optional: whose it is, used only for the colour of the paper. */
    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * The ones still worth showing.
     *
     * Expiry is inclusive of the day itself: a note that says "bins go out
     * Tuesday" is still true on Tuesday.
     *
     * @param  Builder<Note>  $query
     */
    public function scopeLive(Builder $query, string $today): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereNull('expires_on')
            ->orWhere('expires_on', '>=', $today));
    }

    /** @param Builder<Note> $query */
    public function scopeExpired(Builder $query, string $today): void
    {
        $query->whereNotNull('expires_on')->where('expires_on', '<', $today);
    }

    /** The colour of the paper, falling back to a plain one. */
    public function colour(): string
    {
        return $this->member?->colour ?: '#64748b';
    }

    /** "until Tuesday", or nothing at all. */
    public function until(?CarbonImmutable $today = null): ?string
    {
        if (! $this->expires_on) {
            return null;
        }

        $on = CarbonImmutable::parse($this->expires_on);
        $today ??= CarbonImmutable::now();

        return match (true) {
            $on->isSameDay($today) => 'today',
            $on->isSameDay($today->addDay()) => 'tomorrow',
            $on->lessThan($today->addDays(7)) => 'until '.$on->format('l'),
            default => 'until '.$on->format('j M'),
        };
    }
}
