<?php

namespace Tests\Feature\Recipes;

use App\Services\Capture\UrlFetcher;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Getting readable content off a real recipe page. */
class UrlFetcherTest extends TestCase
{
    protected function fetch(string $html): array
    {
        Http::fake(['*' => Http::response($html)]);

        return (new UrlFetcher)->fetch('https://example.test/recipe');
    }

    #[Test]
    public function scripts_are_stripped_from_a_page_far_too_big_for_a_regex(): void
    {
        // The bug this exists for: `<script>.*?</script>` across a megabyte of
        // markup exhausts PCRE's backtrack limit, preg_replace returns null,
        // and a `?? $html` fallback hands the model a page of minified
        // JavaScript instead of a recipe.
        $filler = str_repeat('var x = "'.str_repeat('a', 200).'"; ', 6000);

        $page = $this->fetch(
            '<html><head><title>Curry</title></head><body>'
            ."<script>{$filler}</script>"
            .'<h1>Five ingredient curry</h1><p>750g cannellini beans, 3 tbsp curry paste.</p>'
            .'</body></html>'
        );

        $this->assertGreaterThan(1_000_000, strlen($filler), 'The fixture has to be big enough to trip it.');
        $this->assertStringNotContainsString('var x =', $page['text']);
        $this->assertStringContainsString('cannellini beans', $page['text']);
    }

    #[Test]
    public function a_tag_that_merely_starts_the_same_way_is_not_treated_as_a_script(): void
    {
        $page = $this->fetch('<html><body><scriptural>Keep me</scriptural><p>And me</p></body></html>');

        $this->assertStringContainsString('Keep me', $page['text']);
        $this->assertStringContainsString('And me', $page['text']);
    }

    #[Test]
    public function an_unclosed_script_does_not_swallow_the_whole_page_silently(): void
    {
        $page = $this->fetch('<html><body><p>Before</p><script>oops');

        $this->assertStringContainsString('Before', $page['text']);
        $this->assertStringNotContainsString('oops', $page['text']);
    }

    #[Test]
    public function a_schema_org_recipe_is_read_off_the_page(): void
    {
        $page = $this->fetch($this->pageWithJsonLd([
            '@context' => 'https://schema.org',
            '@type' => 'Recipe',
            'name' => 'Five ingredient curry',
            'recipeYield' => 'Serves 4–6',
            'recipeIngredient' => ['750g cannellini beans', '3 tbsp curry paste'],
            'recipeInstructions' => [
                ['@type' => 'HowToStep', 'text' => 'Fry the paste.'],
                ['@type' => 'HowToStep', 'text' => 'Add everything else.'],
            ],
        ]));

        $this->assertSame('Five ingredient curry', $page['recipe']['name']);
        $this->assertCount(2, $page['recipe']['recipeIngredient']);
    }

    #[Test]
    public function a_recipe_inside_an_at_graph_is_found(): void
    {
        // Which is how the BBC publishes it.
        $page = $this->fetch($this->pageWithJsonLd([
            '@context' => 'https://schema.org',
            '@graph' => [
                ['@type' => 'WebSite', 'name' => 'A site'],
                ['@type' => 'Recipe', 'name' => 'Five ingredient curry', 'recipeIngredient' => ['beans']],
            ],
        ]));

        $this->assertSame('Five ingredient curry', $page['recipe']['name']);
    }

    #[Test]
    public function a_type_given_as_a_list_still_counts(): void
    {
        $page = $this->fetch($this->pageWithJsonLd([
            '@type' => ['Recipe', 'NewsArticle'],
            'name' => 'Five ingredient curry',
        ]));

        $this->assertSame('Five ingredient curry', $page['recipe']['name']);
    }

    #[Test]
    public function a_page_with_no_recipe_data_simply_has_none(): void
    {
        $page = $this->fetch($this->pageWithJsonLd(['@type' => 'WebSite', 'name' => 'A site']));

        $this->assertNull($page['recipe']);
    }

    #[Test]
    public function unreadable_json_ld_is_stepped_over_rather_than_fatal(): void
    {
        $page = $this->fetch(
            '<html><body><script type="application/ld+json">{not json</script>'
            .'<p>Still readable</p></body></html>'
        );

        $this->assertNull($page['recipe']);
        $this->assertStringContainsString('Still readable', $page['text']);
    }

    protected function pageWithJsonLd(array $data): string
    {
        return '<html><head><title>A page</title></head><body>'
            .'<script type="application/ld+json">'.json_encode($data).'</script>'
            .'<p>Some prose.</p></body></html>';
    }
}
