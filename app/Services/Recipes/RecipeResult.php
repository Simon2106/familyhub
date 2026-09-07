<?php

namespace App\Services\Recipes;

/** A recipe as the model returned it, before it is written to the box. */
class RecipeResult
{
    /**
     * @param  list<array{quantity: float|null, unit: string|null, item: string, note: string|null}>  $ingredients
     * @param  list<string>  $steps
     * @param  list<string>  $tags
     */
    public function __construct(
        public readonly string $title,
        public readonly ?int $servings = null,
        public readonly array $ingredients = [],
        public readonly array $steps = [],
        public readonly array $tags = [],
        public readonly ?string $imageUrl = null,
        public readonly ?string $note = null,
    ) {}

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return [
            'title' => $this->title,
            'servings' => $this->servings,
            'ingredients' => $this->ingredients,
            'steps' => $this->steps,
            'tags' => $this->tags,
        ];
    }
}
