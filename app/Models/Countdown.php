<?php

namespace App\Models;

use App\Casts\CalendarDate;
use Database\Factories\CountdownFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Something the household is counting the days to. */
#[Fillable(['household_id', 'event_id', 'label', 'on'])]
class Countdown extends Model
{
    /** @use HasFactory<CountdownFactory> */
    use HasFactory;

    protected function casts(): array
    {
        // CalendarDate, not date: Eloquent's date cast writes a time along
        // with it, MySQL truncates and SQLite does not, and a countdown that
        // is a day out on one database and not the other is worse than none.
        return ['on' => CalendarDate::class];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
