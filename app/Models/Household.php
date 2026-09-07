<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use App\Exceptions\HouseholdNotProvisioned;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'timezone', 'settings'])]
class Household extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /** @return HasMany<Member, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(Member::class)->orderBy('sort_order')->orderBy('name');
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<CalendarAccount, $this> */
    public function calendarAccounts(): HasMany
    {
        return $this->hasMany(CalendarAccount::class);
    }

    /** @return HasMany<Place, $this> */
    public function places(): HasMany
    {
        return $this->hasMany(Place::class)->orderBy('name');
    }

    /** @return HasMany<Checklist, $this> */
    public function checklists(): HasMany
    {
        return $this->hasMany(Checklist::class)->orderBy('sort_order')->orderBy('name');
    }

    /**
     * The zone the family actually lives in. Rows are stored in UTC; this is
     * the zone times are shown in and whose midnight defines "a day".
     */
    public function displayTimezone(): string
    {
        return $this->timezone ?: config('familyhub.timezone');
    }

    /** "Now" on the kitchen wall, not on the server. */
    public function nowLocal(): CarbonImmutable
    {
        return CarbonImmutable::now($this->displayTimezone());
    }

    /** Midnight tonight-just-gone, in household time. */
    public function todayLocal(): CarbonImmutable
    {
        return $this->nowLocal()->startOfDay();
    }

    /**
     * The single household this installation serves. FamilyHub is deliberately
     * single-tenant, so this is cached for the request rather than looked up.
     */
    public static function current(): self
    {
        return once(fn () => static::query()->orderBy('id')->first()
            ?? throw new HouseholdNotProvisioned);
    }
}
