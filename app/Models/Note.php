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
    /** How many post-its fit across the wall at once. The rest scroll. */
    public const ACROSS_THE_WALL = 4;

    /**
     * A ceiling on the rail, not on the board.
     *
     * The wall used to show six and say how many it was not showing; now a
     * finger reaches all of them, so this only exists to keep the row from
     * becoming a hundred notes long.
     */
    public const ON_THE_WALL = 12;

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

    /** Classic post-it, for a note nobody has put their name to. */
    public const PLAIN_PAPER = '#fdf3a0';

    /** How much of the author's colour shows in their paper. */
    public const TINT = 0.22;

    /** The ink line down the side: the author's own colour. */
    public function colour(): string
    {
        return $this->member?->colour ?: '#d9c65a';
    }

    /**
     * The paper this note is written on.
     *
     * The author's colour, mixed most of the way into a warm white — a note
     * has to be read from across a kitchen, so the colour is there to say
     * whose it is at a glance and not to compete with the words on it.
     */
    public function paper(): string
    {
        $colour = $this->member?->colour;

        if (! $colour) {
            return self::PLAIN_PAPER;
        }

        return $this->mix($colour, '#fffdf2', self::TINT);
    }

    /** A shade darker, for the fold along the top edge. */
    public function paperEdge(): string
    {
        $colour = $this->member?->colour;

        return $colour
            ? $this->mix($colour, '#fffdf2', self::TINT + 0.12)
            : '#f6e988';
    }

    /**
     * Two colours, mixed.
     *
     * Done here rather than with CSS color-mix because the wall is one fixed
     * browser and this is one fixed answer: working it out once on the server
     * is cheaper than asking every paint to.
     */
    protected function mix(string $colour, string $into, float $amount): string
    {
        $a = $this->rgb($colour);
        $b = $this->rgb($into);

        if ($a === null || $b === null) {
            return self::PLAIN_PAPER;
        }

        $amount = max(0, min(1, $amount));

        return sprintf(
            '#%02x%02x%02x',
            (int) round($a[0] * $amount + $b[0] * (1 - $amount)),
            (int) round($a[1] * $amount + $b[1] * (1 - $amount)),
            (int) round($a[2] * $amount + $b[2] * (1 - $amount)),
        );
    }

    /** @return array{0: int, 1: int, 2: int}|null */
    protected function rgb(string $hex): ?array
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (! preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return null;
        }

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    /**
     * The tilt, in degrees.
     *
     * Alternating so a row does not lean one way, and derived from the id so
     * a note keeps its own angle rather than jumping about whenever the board
     * is redrawn.
     */
    public function tilt(int $position): float
    {
        $size = 1 + (($this->id ?? $position) % 3);

        return ($position % 2 === 0 ? -1 : 1) * $size;
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
