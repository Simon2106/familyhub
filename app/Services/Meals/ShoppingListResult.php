<?php

namespace App\Services\Meals;

use App\Models\Checklist;

/** What a generate run did, in enough detail to explain itself on screen. */
class ShoppingListResult
{
    public function __construct(
        public readonly Checklist $list,
        public readonly int $added,
        public readonly int $alreadyThere,
        public readonly int $withRecipes,
        public readonly int $freeText,
    ) {}

    /**
     * One sentence for the toast.
     *
     * The free-text count is the important half: without it, a week of
     * "leftovers" and "out" produces an empty list and looks broken.
     */
    public function sentence(): string
    {
        if ($this->withRecipes === 0) {
            return $this->freeText === 0
                ? 'Nothing is planned this week yet.'
                : 'None of this week’s meals have a recipe attached, so there is nothing to add.';
        }

        $parts = [$this->added === 0
            ? 'Everything was already on the list'
            : 'Added '.$this->added.' '.($this->added === 1 ? 'item' : 'items')];

        if ($this->added > 0 && $this->alreadyThere > 0) {
            $parts[] = $this->alreadyThere.' already there';
        }

        if ($this->freeText > 0) {
            $parts[] = $this->freeText.' '.($this->freeText === 1 ? 'meal has' : 'meals have').' no recipe';
        }

        return implode(' · ', $parts).'.';
    }
}
