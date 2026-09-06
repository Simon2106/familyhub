<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['household_id', 'provider', 'label', 'external_account_id', 'principal_url', 'calendar_home_url', 'credentials', 'sync_token', 'status', 'last_synced_at', 'last_error'])]
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

    /** The Apple ID, without exposing the app-specific password alongside it. */
    public function appleId(): ?string
    {
        return $this->external_account_id;
    }
}
