<?php

namespace App\Services\Recipes;

use App\Models\Recipe;

/**
 * The shape a recipe must come back in, and the prompt that goes with it.
 *
 * Same approach as ExtractionSchema, and the same JSON Schema subset applies:
 * no minimum/maximum, nullability through `anyOf` rather than a type union,
 * and `additionalProperties: false` on every object. Both are 400s.
 */
class RecipeSchema
{
    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'What the dish is called, as someone would say it at the table. Never the page title with the site name attached.',
                ],
                'servings' => [
                    'anyOf' => [['type' => 'integer'], ['type' => 'null']],
                    'description' => 'How many it serves, as a whole number. Null if not stated. Do not guess.',
                ],
                'ingredients' => [
                    'type' => 'array',
                    'description' => 'Every ingredient, in the order given. Empty if the source does not list any.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'quantity' => [
                                'anyOf' => [['type' => 'number'], ['type' => 'null']],
                                'description' => 'A number. Convert fractions and words: "½" is 0.5, "a dozen" is 12. Null for "a splash of" or an unmeasured ingredient.',
                            ],
                            'unit' => self::nullableString(
                                'The unit as written, lowercased and singular: g, kg, ml, l, tbsp, tsp, clove, tin, handful. Null when the quantity is a bare count ("2 onions").'
                            ),
                            'item' => [
                                'type' => 'string',
                                'description' => 'The ingredient itself, singular where natural: "onion", "plain flour", "chicken thigh". No quantity, no unit, no preparation.',
                            ],
                            'note' => self::nullableString(
                                'Preparation or detail that came with it: "finely chopped", "at room temperature", "plus extra to serve". Null if there was none.'
                            ),
                        ],
                        'required' => ['quantity', 'unit', 'item', 'note'],
                        'additionalProperties' => false,
                    ],
                ],
                'steps' => [
                    'type' => 'array',
                    'description' => 'The method, one step per entry, in order. Empty if the source gives no method — that is common and fine.',
                    'items' => ['type' => 'string'],
                ],
                'tags' => [
                    'type' => 'array',
                    'description' => 'A few short lowercase tags. Prefer these where they fit: '
                        .implode(', ', Recipe::SUGGESTED_TAGS)
                        .'. Add others only when they say something those do not.',
                    'items' => ['type' => 'string'],
                ],
                'image_url' => self::nullableString(
                    'An absolute URL for a photo of the finished dish, if the material gives one. Null otherwise.'
                ),
                'note' => self::nullableString(
                    'One short sentence for the card when something was missing or uncertain — the method was behind a login, the quantities were for a doubled batch. Null when the recipe is complete.'
                ),
            ],
            'required' => ['title', 'servings', 'ingredients', 'steps', 'tags', 'image_url', 'note'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    protected static function nullableString(string $description): array
    {
        return [
            'anyOf' => [['type' => 'string'], ['type' => 'null']],
            'description' => $description,
        ];
    }

    public static function systemPrompt(): string
    {
        return <<<'PROMPT'
        You are reading something a family saved because they might cook it. Turn it into
        a recipe card.

        ## What you are usually given

        A web page reduced to text, a caption copied from Instagram or TikTok, a few lines
        someone typed, or a photograph of a cookbook page or a handwritten card. All of
        them are worth saving. Take what is there and do not complain about what is not.

        ## The one thing that matters most

        Get the title right. A page's text carries navigation, a newsletter sign-up, a
        cookie banner and forty comments; the dish is one line in the middle of it. Give
        the dish its own name — "Chicken and chorizo traybake" — not "Chicken and chorizo
        traybake recipe | BBC Good Food | Easy midweek dinners".

        ## Ingredients

        Split each line into a number, a unit and the thing itself, because these are
        going to be merged into a shopping list later. "2 tbsp olive oil, plus extra for
        drizzling" is quantity 2, unit tbsp, item olive oil, note "plus extra for
        drizzling". "1 large onion, finely chopped" is quantity 1, unit null, item onion,
        note "large, finely chopped".

        Keep the item as the thing you would buy. "Plain flour", not "150g of plain flour
        sifted". Somebody is going to read this in a supermarket aisle.

        A social-media caption often has no measurements at all. List the ingredients it
        does name, with null quantities, rather than inventing amounts.

        ## Method

        One step per entry, in the order given. If the material has no method — a photo of
        a finished dish, a caption that says "recipe in bio" — return an empty list and say
        so in `note`. An empty method is not a failure.

        ## When the source was thin

        Say so in `note`, in one sentence a person would write: "The link needed a login,
        so this is from the caption you shared." That single line is what makes a
        half-filled card trustworthy rather than broken.

        ## What not to do

        Do not invent quantities, times, temperatures or steps that were not there. A
        recipe someone cooks from has to be the recipe they saved.
        PROMPT;
    }
}
