<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

#[Fillable(['household_id', 'name', 'colour', 'avatar_path', 'is_child', 'pin', 'sort_order'])]
#[Hidden(['pin'])]
class Member extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_child' => 'boolean',
            'pin' => 'hashed',
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

    /** @return HasManyThrough<Event, Calendar, $this> */
    public function events(): HasManyThrough
    {
        return $this->hasManyThrough(Event::class, Calendar::class);
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
