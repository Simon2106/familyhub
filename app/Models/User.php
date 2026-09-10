<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['household_id', 'member_id', 'name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notify_settings' => 'array',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * The family member this login represents, used to default "my" views.
     *
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return HasMany<PushSubscription, $this> */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    /**
     * Which member this login speaks for.
     *
     * Ratings are one per adult, so somebody has to be doing the rating. A
     * login is normally linked to a member in /admin; where it is not, the
     * name is the next best thing, and a household with one adult needs no
     * disambiguating at all.
     */
    public function asMember(): ?Member
    {
        if ($this->member) {
            return $this->member;
        }

        $adults = Member::where('household_id', $this->household_id)
            ->where('is_child', false)
            ->get();

        return $adults->firstWhere(
            fn (Member $m) => mb_strtolower($m->name) === mb_strtolower((string) $this->name)
        ) ?? ($adults->count() === 1 ? $adults->first() : null);
    }
}
