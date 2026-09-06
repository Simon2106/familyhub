<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['calendar_account_id', 'member_id', 'external_id', 'name', 'colour', 'is_visible', 'is_writable', 'sync_token', 'ctag', 'last_synced_at'])]
class Calendar extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
            'is_writable' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CalendarAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(CalendarAccount::class, 'calendar_account_id');
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return HasMany<Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /** @param Builder<Calendar> $query */
    public function scopeVisible(Builder $query): void
    {
        $query->where('is_visible', true);
    }
}
