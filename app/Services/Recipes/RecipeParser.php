<?php

namespace App\Services\Recipes;

use RuntimeException;

/** Turns the model's JSON into a RecipeResult, defending against the edges. */
class RecipeParser
{
    public static function fromJson(string $json): RecipeResult
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('The recipe did not come back as valid JSON.');
        }

        return self::fromArray($decoded);
    }

    /** @param array<string, mixed> $decoded */
    public static function fromArray(array $decoded): RecipeResult
    {
        $title = self::text($decoded['title'] ?? null);

        if ($title === null) {
            throw new RuntimeException('No dish could be found in that.');
        }

        return new RecipeResult(
            title: mb_substr($title, 0, 250),
            servings: self::servings($decoded['servings'] ?? null),
            ingredients: self::ingredients($decoded['ingredients'] ?? []),
            steps: self::strings($decoded['steps'] ?? []),
            tags: self::tags($decoded['tags'] ?? []),
            imageUrl: self::url($decoded['image_url'] ?? null),
            note: self::text($decoded['note'] ?? null),
        );
    }

    /**
     * @return list<array{quantity: float|null, unit: string|null, item: string, note: string|null}>
     */
    protected static function ingredients(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ($item = self::text($row['item'] ?? null)) === null) {
                continue;
            }

            $quantity = $row['quantity'] ?? null;

            $out[] = [
                // A negative or absurd quantity is a misread, not an amount.
                'quantity' => is_numeric($quantity) && (float) $quantity > 0 ? (float) $quantity : null,
                'unit' => self::unit($row['unit'] ?? null),
                'item' => mb_strtolower(mb_substr($item, 0, 120)),
                'note' => self::text($row['note'] ?? null),
            ];
        }

        return $out;
    }

    protected static function unit(mixed $value): ?string
    {
        $unit = self::text($value);

        return $unit === null ? null : mb_strtolower(mb_substr($unit, 0, 24));
    }

    protected static function servings(mixed $value): ?int
    {
        // Guarded here rather than in the schema: structured outputs reject
        // minimum/maximum, so ranges are stated in the description and
        // enforced after the fact.
        return is_numeric($value) && (int) $value > 0 && (int) $value <= 100 ? (int) $value : null;
    }

    /**
     * @return list<string>
     */
    protected static function strings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($v) => is_string($v) ? trim($v) : null,
            $values,
        )));
    }

    /** @return list<string> */
    protected static function tags(mixed $values): array
    {
        $tags = array_map(
            fn (string $tag) => mb_strtolower(mb_substr($tag, 0, 30)),
            self::strings($values),
        );

        return array_values(array_slice(array_unique($tags), 0, 8));
    }

    protected static function url(mixed $value): ?string
    {
        $url = self::text($value);

        return $url !== null && preg_match('#^https?://#i', $url) ? mb_substr($url, 0, 2000) : null;
    }

    protected static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
