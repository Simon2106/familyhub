<?php

namespace Tests\Support;

use App\Services\Recipes\Contracts\RecipeReader;
use App\Services\Recipes\RecipeParser;
use App\Services\Recipes\RecipeResult;
use RuntimeException;

/**
 * Stands in for the API so the whole import pipeline can be exercised.
 *
 * Records what it was sent, because "was the photo actually included?" is the
 * question these tests exist to answer.
 */
class FakeRecipeReader implements RecipeReader
{
    /** @var list<list<array<string, mixed>>> */
    public array $calls = [];

    protected ?RecipeResult $result = null;

    protected ?string $failWith = null;

    /**
     * @param  array<string, mixed>|RecipeResult  $result  Raw model output, or a
     *                                                     ready-made result.
     *
     * Raw arrays go through the real parser, so a test can never assert on
     * data the production path would have normalised or thrown away.
     */
    public function returns(array|RecipeResult $result): self
    {
        $this->result = is_array($result) ? RecipeParser::fromArray($result) : $result;

        return $this;
    }

    public function fails(string $message): self
    {
        $this->failWith = $message;

        return $this;
    }

    public function read(array $content): RecipeResult
    {
        $this->calls[] = $content;

        if ($this->failWith !== null) {
            throw new RuntimeException($this->failWith);
        }

        return $this->result ?? new RecipeResult(
            title: 'Chicken and chorizo traybake',
            servings: 4,
            ingredients: [
                ['quantity' => 4.0, 'unit' => null, 'item' => 'chicken thigh', 'note' => null],
                ['quantity' => 200.0, 'unit' => 'g', 'item' => 'chorizo', 'note' => 'sliced'],
            ],
            steps: ['Heat the oven to 200C.'],
            tags: ['quick', 'kids'],
        );
    }

    /** The text actually sent to the model, joined across every block. */
    public function sentText(): string
    {
        return collect($this->calls)
            ->flatten(1)
            ->where('type', 'text')
            ->pluck('text')
            ->implode("\n");
    }

    /** @return list<string> the block types of the most recent call */
    public function lastBlockTypes(): array
    {
        return array_map(fn (array $b) => $b['type'], $this->calls[array_key_last($this->calls)] ?? []);
    }
}
