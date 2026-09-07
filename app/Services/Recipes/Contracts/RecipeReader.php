<?php

namespace App\Services\Recipes\Contracts;

use App\Services\Recipes\RecipeResult;

/**
 * Reads a recipe out of whatever was saved.
 *
 * An interface so the whole import pipeline can be exercised in tests without
 * calling the API, the same way ItemExtractor is.
 */
interface RecipeReader
{
    /** @param list<array<string, mixed>> $content Messages API content blocks. */
    public function read(array $content): RecipeResult;
}
