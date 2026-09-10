<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['household_id', 'provider', 'label', 'external_account_id', 'feed_url', 'refresh_minutes', 'principal_url', 'calendar_home_url', 'credentials', 'sync_token', 'status', 'last_synced_at', 'last_error'])]
#[Hidden(['credentials'])]
class CalendarAccount extends Model
{
    use HasFactory;

    /**
     * iCloud is the only provider. Google is out of scope per the brief; the
     * column stays a string so another provider can be added without a
     * migration if that changes.
     */
    public const PROVIDER_ICLOUD = 'icloud';

    /**
     * A subscribed .ics feed — a school's fixtures, a club's season.
     *
     * Read-only by construction, not by policy: there is no writer for this
     * provider, and the calendars it makes are created is_writable = false.
     */
    public const PROVIDER_ICS = 'ics';

    /** How often a feed is re-read when nobody has said. */
    public const DEFAULT_REFRESH_MINUTES = 360;

    /** The intervals worth offering. Nobody needs a fixtures list every minute. */
    public const REFRESH_CHOICES = [
        60 => 'Every hour',
        360 => 'Every six hours',
        1440 => 'Once a day',
    ];

    protected function casts(): array
    {
        return [
            // OAuth tokens and iCloud app-specific passwords never sit in the clear.
            'credentials' => 'encrypted:array',
            'last_synced_at' => 'datetime',
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

    public function isHealthy(): bool
    {
        return $this->status === 'ok';
    }

    public function isSubscription(): bool
    {
        return $this->provider === self::PROVIDER_ICS;
    }

    public function refreshMinutes(): int
    {
        $minutes = (int) ($this->refresh_minutes ?: self::DEFAULT_REFRESH_MINUTES);

        return array_key_exists($minutes, self::REFRESH_CHOICES) ? $minutes : self::DEFAULT_REFRESH_MINUTES;
    }

    /** Whether enough time has passed to be worth asking again. */
    public function isDueForRefresh(?CarbonImmutable $now = null): bool
    {
        if (! $this->last_synced_at) {
            return true;
        }

        $now ??= CarbonImmutable::now();

        return $this->last_synced_at->lessThanOrEqualTo($now->subMinutes($this->refreshMinutes()));
    }

    /** The Apple ID, without exposing the app-specific password alongside it. */
    public function appleId(): ?string
    {
        return $this->external_account_id;
    }
}
