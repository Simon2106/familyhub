<?php

namespace App\Models;

use Database\Factories\RecipeRatingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's opinion of one idea.
 *
 * Adults leave stars, children leave a thumb. Kept as one row per person per
 * idea — cooking it again updates your row rather than adding another, because
 * "what do we think of this?" is the question, not "what did we think of it on
 * the fourteenth?".
 */
#[Fillable(['recipe_id', 'member_id', 'stars', 'thumbs', 'note'])]
class RecipeRating extends Model
{
    /** @use HasFactory<RecipeRatingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['stars' => 'integer', 'thumbs' => 'integer'];
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function isUp(): bool
    {
        return $this->thumbs !== null && $this->thumbs > 0;
    }

    public function isDown(): bool
    {
        return $this->thumbs !== null && $this->thumbs < 0;
    }
}
