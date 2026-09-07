<?php

namespace App\Services\Recipes;

/**
 * Decides whether something shared to FamilyHub is a meal idea.
 *
 * Deliberately conservative. Both destinations offer a one-tap correction, so
 * the cost of guessing wrong is small — but a school newsletter that lands in
 * the recipe box is more confusing than a recipe that lands in Review, so this
 * only says yes when it has a real reason to.
 */
class LooksLikeAMealIdea
{
    /**
     * Hosts where a share is nearly always food.
     *
     * The social three are here because the family shares reels and posts far
     * more often than anything else, and those links need the caption fallback
     * that only the recipe importer does.
     */
    protected const FOOD_HOSTS = [
        'instagram.com', 'tiktok.com', 'facebook.com', 'fb.watch', 'pinterest.com', 'pin.it',
        'bbcgoodfood.com', 'bbc.co.uk/food', 'allrecipes.com', 'deliciousmagazine.co.uk',
        'jamieoliver.com', 'seriouseats.com', 'cooking.nytimes.com', 'thekitchn.com',
        'sainsburysmagazine.co.uk', 'olivemagazine.com', 'greatbritishchefs.com',
    ];

    public function decide(?string $url, ?string $text): bool
    {
        return $this->fromHost($url) || $this->fromText($text);
    }

    protected function fromHost(?string $url): bool
    {
        if (! $url) {
            return false;
        }

        $needle = strtolower((parse_url($url, PHP_URL_HOST) ?: '').(parse_url($url, PHP_URL_PATH) ?: ''));

        foreach (self::FOOD_HOSTS as $host) {
            if (str_contains($needle, $host)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Two signals, not one.
     *
     * "Ingredients" alone turns up in newsletters about allergens; paired with
     * a method or a serving count it is a recipe.
     */
    protected function fromText(?string $text): bool
    {
        if (! $text) {
            return false;
        }

        $text = strtolower($text);

        if (! str_contains($text, 'ingredient')) {
            return false;
        }

        foreach (['method', 'serves', 'preheat', 'tbsp', 'tsp', 'simmer', 'season to taste'] as $corroboration) {
            if (str_contains($text, $corroboration)) {
                return true;
            }
        }

        return false;
    }
}
