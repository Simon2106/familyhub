<?php

namespace App\Models;

use App\Casts\CalendarDate;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One step, ticked, on one day. The whole of a routine's memory. */
#[Fillable(['routine_step_id', 'on', 'completed_at'])]
class RoutineStepCompletion extends Model
{
    protected function casts(): array
    {
        return ['on' => CalendarDate::class, 'completed_at' => 'datetime'];
    }

    /** @return BelongsTo<RoutineStep, $this> */
    public function step(): BelongsTo
    {
        return $this->belongsTo(RoutineStep::class, 'routine_step_id');
    }
}
