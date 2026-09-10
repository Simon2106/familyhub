<?php

namespace App\Models;

use App\Services\Notifications\ReminderTime;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One reminder somebody built: who, what and when.
 *
 * Deliberately not a general rules engine. Four ways of saying "what" and
 * three ways of saying "when" covers everything the family actually asked
 * for, and each extra dimension is one more thing to get wrong in a dialog
 * nobody wants to be in for long.
 */
#[Fillable([
    'user_id', 'household_id', 'name', 'is_active', 'scope',
    'calendar_id', 'place_id', 'event_id', 'keyword',
    'include_repeats', 'members', 'times', 'is_one_off', 'sort_order',
])]
class ReminderRule extends Model
{
    public const SCOPES = [
        'all' => 'Everything',
        'calendar' => 'One calendar',
        'place' => 'A place',
        'keyword' => 'A word in the title',
        'event' => 'One event',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'include_repeats' => 'boolean',
            'is_one_off' => 'boolean',
            'members' => 'array',
            'times' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return BelongsTo<Calendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    /** @return BelongsTo<Place, $this> */
    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @param Builder<ReminderRule> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The member ids this rule cares about. Empty means anyone.
     *
     * @return list<int>
     */
    public function memberIds(): array
    {
        return array_values(array_filter(array_map('intval', (array) ($this->members ?? []))));
    }

    public function forAnyone(): bool
    {
        return $this->memberIds() === [];
    }

    /** @return list<ReminderTime> */
    public function reminderTimes(): array
    {
        return ReminderTime::list((array) ($this->times ?? []));
    }

    /** How far ahead this rule ever needs to look. */
    public function furthestLeadMinutes(): int
    {
        $times = $this->reminderTimes();

        return $times === [] ? 0 : max(array_map(fn (ReminderTime $t) => $t->roughLeadMinutes(), $times));
    }

    /**
     * The rule in a sentence, for the list.
     *
     * Written out rather than shown as three chips because a rule is a thing
     * somebody has to be able to check at a glance — "is that the one that
     * does the dentist?" — and three chips is a puzzle.
     */
    public function summary(): string
    {
        $who = $this->forAnyone()
            ? 'Anyone'
            : Member::whereIn('id', $this->memberIds())->orderBy('sort_order')->pluck('name')->join(', ', ' and ');

        $what = match ($this->scope) {
            'calendar' => 'on '.($this->calendar?->name ?? 'a calendar that has gone'),
            'place' => 'at '.($this->place?->name ?? 'a place that has gone'),
            'keyword' => 'with “'.$this->keyword.'” in the title',
            'event' => $this->event
                ? '— '.Str::limit($this->event->title, 40).($this->include_repeats ? ' and its repeats' : '')
                : '— an event that has gone',
            default => '',
        };

        $when = collect($this->reminderTimes())
            ->map(fn (ReminderTime $t) => mb_strtolower($t->label()))
            ->join(', ', ' and ');

        return trim($who.' '.$what).($when !== '' ? ' · '.$when : '');
    }

    /** Whether this rule can still match anything at all. */
    public function isBroken(): bool
    {
        return match ($this->scope) {
            'calendar' => $this->calendar_id === null,
            'place' => $this->place_id === null,
            'event' => $this->event_id === null,
            'keyword' => blank($this->keyword),
            default => false,
        } || $this->reminderTimes() === [];
    }
}
