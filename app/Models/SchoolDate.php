<?php

namespace App\Models;

use App\Casts\CalendarDate;
use Database\Factories\SchoolDateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One term, one INSET day, or one named closure. */
#[Fillable(['place_id', 'kind', 'name', 'starts_on', 'ends_on', 'source'])]
class SchoolDate extends Model
{
    /** @use HasFactory<SchoolDateFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = ['kind' => 'term', 'source' => 'manual'];

    protected function casts(): array
    {
        return ['starts_on' => CalendarDate::class, 'ends_on' => CalendarDate::class];
    }

    /** @return BelongsTo<Place, $this> */
    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    public function isTerm(): bool
    {
        return $this->kind === 'term';
    }

    public function days(): int
    {
        return (int) $this->starts_on->diffInDays($this->ends_on) + 1;
    }

    /** @param Builder<SchoolDate> $query */
    public function scopeTerms(Builder $query): void
    {
        $query->where('kind', 'term');
    }

    /** @param Builder<SchoolDate> $query */
    public function scopeOverlapping(Builder $query, string $from, string $to): void
    {
        $query->where('starts_on', '<=', $to)->where('ends_on', '>=', $from);
    }
}
