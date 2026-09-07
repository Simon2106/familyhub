<?php

namespace App\Models;

use Database\Factories\HomeTileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One chosen entity, as it appears on the wall's Home tab. */
#[Fillable(['household_id', 'entity_id', 'domain', 'name', 'label', 'area', 'sort_order'])]
class HomeTile extends Model
{
    /** @use HasFactory<HomeTileFactory> */
    use HasFactory;

    /** Tiles with no room of their own still need somewhere to go. */
    public const UNGROUPED = 'Everything else';

    /** @var array<string, mixed> */
    protected $attributes = ['sort_order' => 0];

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** What the household calls it, falling back to what HA calls it. */
    public function title(): string
    {
        return $this->label ?: $this->name;
    }

    public function room(): string
    {
        return $this->area ?: self::UNGROUPED;
    }
}
