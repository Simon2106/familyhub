<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One thing to do in a routine — "teeth", "shoes on". */
#[Fillable(['routine_id', 'title', 'icon', 'sort_order'])]
class RoutineStep extends Model
{
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = ['sort_order' => 0];

    /** @return BelongsTo<Routine, $this> */
    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }

    /** @return HasMany<RoutineStepCompletion, $this> */
    public function completions(): HasMany
    {
        return $this->hasMany(RoutineStepCompletion::class);
    }
}
