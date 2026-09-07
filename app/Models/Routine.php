<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\RoutineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A named sequence a child runs at the same time each day. */
#[Fillable(['household_id', 'member_id', 'kind', 'name', 'starts_at', 'ends_at', 'is_active', 'sort_order'])]
class Routine extends Model
{
    /** @use HasFactory<RoutineFactory> */
    use HasFactory;

    public const KINDS = ['morning' => 'Morning', 'after_school' => 'After school', 'bedtime' => 'Bedtime'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'kind' => 'morning',
        'starts_at' => '07:00:00',
        'ends_at' => '08:30:00',
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return HasMany<RoutineStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(RoutineStep::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Whether this routine is worth showing right now.
     *
     * Compared as wall-clock minutes in household time. A window that ends
     * before it starts has wrapped past midnight — a bedtime routine running
     * 20:00 to 00:30 is a real thing to want.
     */
    public function isActiveAt(CarbonImmutable $now): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $minutes = $now->hour * 60 + $now->minute;
        $from = $this->minutesOf($this->starts_at);
        $to = $this->minutesOf($this->ends_at);

        return $from <= $to
            ? $minutes >= $from && $minutes < $to
            : $minutes >= $from || $minutes < $to;
    }

    public function label(): string
    {
        return $this->name ?: (self::KINDS[$this->kind] ?? 'Routine');
    }

    public function windowLabel(): string
    {
        return substr((string) $this->starts_at, 0, 5).'–'.substr((string) $this->ends_at, 0, 5);
    }

    protected function minutesOf(mixed $time): int
    {
        [$hours, $minutes] = array_pad(explode(':', (string) $time), 2, '0');

        return ((int) $hours) * 60 + (int) $minutes;
    }

    /** @param Builder<Routine> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
