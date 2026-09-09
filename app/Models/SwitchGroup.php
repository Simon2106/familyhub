<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SwitchGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A handful of switches the household thinks of as one thing.
 *
 * "Lamps" is not a device; it is a habit. Keeping the grouping here rather
 * than in Home Assistant means it can be changed from the kitchen wall, and
 * that a group is free to behave in ways HA has no idea about — chiefly to go
 * off five minutes after somebody asks it to.
 */
#[Fillable([
    'household_id', 'name', 'sort_order', 'on_delay', 'off_delay',
    'on_trigger', 'on_time', 'off_trigger', 'off_time', 'days',
])]
class SwitchGroup extends Model
{
    /** @use HasFactory<SwitchGroupFactory> */
    use HasFactory;

    /** What a scheduled direction can be hung off. */
    public const TRIGGERS = ['time' => 'At a time', 'sunrise' => 'At sunrise', 'sunset' => 'At sunset'];

    /** Nobody wants a countdown longer than this, and a typo should not start one. */
    public const MAX_DELAY = 240;

    /** @var array<string, mixed> */
    protected $attributes = ['on_delay' => 0, 'off_delay' => 0, 'sort_order' => 0];

    protected function casts(): array
    {
        return [
            'days' => 'array',
            'pending_fires_at' => 'datetime',
            'on_fired_on' => 'date',
            'off_fired_on' => 'date',
        ];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return HasMany<SwitchGroupEntity, $this> */
    public function entities(): HasMany
    {
        return $this->hasMany(SwitchGroupEntity::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return list<string> */
    public function entityIds(): array
    {
        return $this->entities->pluck('entity_id')->all();
    }

    /** Minutes before this direction actually happens. 0 is instant. */
    public function delayFor(string $direction): int
    {
        return (int) ($direction === 'on' ? $this->on_delay : $this->off_delay);
    }

    public function isInstant(string $direction): bool
    {
        return $this->delayFor($direction) === 0;
    }

    public function hasPending(): bool
    {
        return $this->pending_direction !== null && $this->pending_fires_at !== null;
    }

    /** Seconds left on the countdown, or null when nothing is pending. */
    public function secondsLeft(?CarbonImmutable $now = null): ?int
    {
        if (! $this->hasPending()) {
            return null;
        }

        $now ??= CarbonImmutable::now();

        return max(0, $now->diffInSeconds($this->pending_fires_at, false));
    }

    /** Whether a schedule runs today. An empty list means every day. */
    public function runsOn(CarbonImmutable $date): bool
    {
        $days = array_map('intval', $this->days ?? []);

        return $days === [] || in_array($date->dayOfWeekIso, $days, true);
    }

    public function scheduled(string $direction): bool
    {
        return ($direction === 'on' ? $this->on_trigger : $this->off_trigger) !== null;
    }
}
