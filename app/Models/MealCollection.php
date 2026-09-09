<?php

namespace App\Models;

use Database\Factories\MealCollectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A pile of ideas somebody made on purpose.
 *
 * Not a tag. A tag says what a meal is — quick, veggie, batch — and the model
 * can guess at those. "Jenna's picks" is a judgement nobody but Jenna can
 * make, so these are only ever created by hand.
 */
#[Fillable(['household_id', 'name', 'sort_order'])]
class MealCollection extends Model
{
    /** @use HasFactory<MealCollectionFactory> */
    use HasFactory;

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return BelongsToMany<Recipe, $this> */
    public function recipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class, 'meal_collection_recipe');
    }
}
