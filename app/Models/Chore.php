<?php

namespace App\Models;

use App\Casts\CalendarDate;
use Carbon\CarbonImmutable;
use Database\Factories\ChoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A standing chore: what, who, how often, what it is worth.
 *
 * The recurrence is deliberately four fixed shapes rather than an RRULE. A
 * parent setting up "bins on Thursdays" should be answering a question, not
 * composing a rule, and everything a household actually wants fits in these.
 */
#[Fillable([
    'household_id', 'member_id', 'title', 'icon', 'recurrence', 'days',
    'points', 'needs_approval', 'is_active', 'sort_order', 'starts_on', 'ends_on',
])]
class Chore extends Model
{
    /** @use HasFactory<ChoreFactory> */
    use HasFactory;

    public const RECURRENCES = ['daily', 'weekdays', 'days', 'weekly'];

    /** ISO weekday numbers, Monday first, as Carbon reports them. */
    public const WEEKDAYS = [1, 2, 3, 4, 5];

    /**
     * Mirrors of the column defaults.
     *
     * Without these a freshly created Chore has null for is_active in memory —
     * the database default is applied on insert but never read back — so
     * occursOn() answers "no" for every day until something reloads it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'recurrence' => 'daily',
        'points' => 0,
        'needs_approval' => false,
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'days' => 'array',
            'needs_approval' => 'boolean',
            'is_active' => 'boolean',
            'starts_on' => CalendarDate::class,
            'ends_on' => CalendarDate::class,
        ];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return HasMany<ChoreInstance, $this> */
    public function instances(): HasMany
    {
        return $this->hasMany(ChoreInstance::class);
    }

    /**
     * Whether this chore falls on a given day.
     *
     * The only place recurrence is interpreted. Everything else — the wall,
     * the child's view, the parent summary — asks this rather than reasoning
     * about weekday numbers on its own.
     */
    public function occursOn(CarbonImmutable $date): bool
    {
        if (! $this->is_active || ! $this->withinRun($date)) {
            return false;
        }

        return match ($this->recurrence) {
            'daily' => true,
            'weekdays' => in_array($date->dayOfWeekIso, self::WEEKDAYS, true),
            // `weekly` is a single chosen day, so it reads the same list;
            // the difference is only in how the two are asked for.
            'days', 'weekly' => in_array($date->dayOfWeekIso, $this->weekdays(), true),
            default => false,
        };
    }

    /** @return list<int> */
    public function weekdays(): array
    {
        return match ($this->recurrence) {
            'daily' => [1, 2, 3, 4, 5, 6, 7],
            'weekdays' => self::WEEKDAYS,
            default => array_values(array_filter(
                array_map('intval', $this->days ?? []),
                fn (int $day) => $day >= 1 && $day <= 7,
            )),
        };
    }

    /** How often it happens, in the words a parent would use. */
    public function scheduleLabel(): string
    {
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

        return match ($this->recurrence) {
            'daily' => 'Every day',
            'weekdays' => 'School days',
            'weekly' => 'Every '.($names[$this->weekdays()[0] ?? 1] ?? 'week'),
            default => $this->weekdays() === []
                ? 'No days chosen'
                : implode(', ', array_map(fn (int $d) => $names[$d], $this->weekdays())),
        };
    }

    /** A chore added today should not appear on days before it existed. */
    protected function withinRun(CarbonImmutable $date): bool
    {
        if ($this->starts_on && $date->lessThan($this->starts_on)) {
            return false;
        }

        return ! ($this->ends_on && $date->greaterThan($this->ends_on));
    }

    /** @param Builder<Chore> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
