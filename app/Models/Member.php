<?php

namespace App\Models;

use App\Casts\CalendarDate;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

#[Fillable(['household_id', 'name', 'colour', 'avatar_path', 'is_child', 'pin', 'sort_order', 'birthday'])]
#[Hidden(['pin'])]
class Member extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_child' => 'boolean',
            'pin' => 'hashed',
            // CalendarDate rather than date: a birthday with a time on it is
            // a birthday that lands on the wrong day in MySQL.
            'birthday' => CalendarDate::class,
        ];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return HasMany<Calendar, $this> */
    public function calendars(): HasMany
    {
        return $this->hasMany(Calendar::class);
    }

    /** Events on calendars this member owns. */
    /** @return HasManyThrough<Event, Calendar, $this> */
    public function ownedCalendarEvents(): HasManyThrough
    {
        return $this->hasManyThrough(Event::class, Calendar::class);
    }

    /**
     * Events attributed to this member, however they were matched.
     *
     * @return BelongsToMany<Event, $this>
     */
    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class)->withPivot('reason')->withTimestamps();
    }

    /** @return MorphMany<Alias, $this> */
    public function aliases(): MorphMany
    {
        return $this->morphMany(Alias::class, 'aliasable');
    }

    /** Email domains that belong to this person — their school's, their club's. */
    /** @return MorphMany<Alias, $this> */
    public function senderDomains(): MorphMany
    {
        return $this->aliases()->domains();
    }

    /** @return BelongsToMany<Place, $this> */
    public function places(): BelongsToMany
    {
        return $this->belongsToMany(Place::class)
            ->withPivot('include_automatically')
            ->withTimestamps();
    }

    /**
     * Every string this member answers to. The name itself always counts, so a
     * member with no aliases configured still matches.
     *
     * @return list<string>
     */
    public function matchTerms(): array
    {
        return collect([$this->name])
            // Names only: an email domain is not something anyone writes in an
            // event title, and matching one there would be nonsense.
            ->merge($this->aliases->where('kind', 'name')->pluck('alias'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @param Builder<Member> $query */
    public function scopeChildren(Builder $query): void
    {
        $query->where('is_child', true);
    }

    public function checkPin(string $pin): bool
    {
        return $this->pin !== null && Hash::check($pin, $this->pin);
    }

    /**
     * Give the member their first name as an alias.
     *
     * Only when it differs from the full name — matchTerms() already includes
     * the name itself, so "Simon" needs no alias while "Simon Williams" does.
     */
    public function ensureFirstNameAlias(): void
    {
        $first = trim((string) (preg_split('/\s+/u', trim($this->name))[0] ?? ''));

        if ($first === '' || strcasecmp($first, trim($this->name)) === 0) {
            return;
        }

        $this->aliases()->firstOrCreate(['alias' => $first]);
    }

    /** Two-letter fallback used when a member has no avatar image. */
    public function initials(): string
    {
        $words = preg_split('/\s+/', trim($this->name)) ?: [];

        return mb_strtoupper(mb_substr($words[0] ?? '', 0, 1).mb_substr($words[1] ?? '', 0, 1)) ?: '?';
    }

    public function avatarUrl(): ?string
    {
        if (! $this->avatar_path) {
            return null;
        }

        return Storage::disk(config('familyhub.photos.disk'))->url($this->avatar_path);
    }
}
