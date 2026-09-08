<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A named organisation that shows up in event titles — a school, a workplace,
 * a club. Attached to the members it concerns.
 */
#[Fillable(['household_id', 'name', 'type'])]
class Place extends Model
{
    use HasFactory;

    public const TYPES = ['school', 'work', 'club', 'other'];

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return MorphMany<Alias, $this> */
    public function aliases(): MorphMany
    {
        return $this->morphMany(Alias::class, 'aliasable');
    }

    /** Email domains that belong to this place. */
    /** @return MorphMany<Alias, $this> */
    public function senderDomains(): MorphMany
    {
        return $this->aliases()->domains();
    }

    /** @return BelongsToMany<Member, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Member::class)
            ->withPivot('include_automatically')
            ->withTimestamps();
    }

    /**
     * The members a match on this place should pull in on its own.
     *
     * @return BelongsToMany<Member, $this>
     */
    public function automaticMembers(): BelongsToMany
    {
        return $this->members()->wherePivot('include_automatically', true);
    }

    /**
     * Every string this place answers to, its own name included.
     *
     * @return list<string>
     */
    public function matchTerms(): array
    {
        return collect([$this->name])
            // Names only. A domain belongs to the place but is never written
            // in an event title.
            ->merge($this->aliases->where('kind', 'name')->pluck('alias'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function typeLabel(): string
    {
        return ucfirst($this->type);
    }
}
