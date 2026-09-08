<?php

namespace App\Services\Capture;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fetches a page and reduces it to readable text.
 *
 * Deliberately crude: the model reads prose perfectly well, and a real HTML
 * parser would be another dependency for very little gain.
 */
class UrlFetcher
{
    public const MAX_CHARS = 60_000;

    /** @return array{title: ?string, text: string, image: ?string, description: ?string, recipe: ?array<string, mixed>} */
    public function fetch(string $url): array
    {
        if (! preg_match('#^https?://#i', $url)) {
            throw new RuntimeException('Only http and https addresses can be captured.');
        }

        $response = Http::timeout(20)
            ->withHeaders(['User-Agent' => 'FamilyHub/1.0 (+capture)'])
            ->get($url);

        if ($response->failed()) {
            throw new RuntimeException("That page returned {$response->status()}.");
        }

        $html = $response->body();

        return [
            'title' => $this->title($html),
            'text' => $this->text($html),
            'image' => $this->image($html, $url),
            // The one thing a login wall still tells us. Read separately
            // because text() throws the whole <head> away before stripping
            // tags — which is where a caption lives, so the only readable
            // thing on an Instagram page was being discarded on the way past.
            'description' => $this->description($html),
            // Most recipe sites publish the whole thing as schema.org data for
            // search engines. Reading that beats guessing at the prose.
            'recipe' => $this->structuredRecipe($html),
        ];
    }

    /**
     * The page's own preview image, from the tag it already publishes for
     * social cards. A recipe card without a photograph of the food is a much
     * less appetising thing to scroll past.
     */
    protected function image(string $html, string $base): ?string
    {
        $patterns = [
            '#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']#i',
            '#<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']#i',
            '#<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']#i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                $url = html_entity_decode(trim($m[1]));

                if (str_starts_with($url, '//')) {
                    $url = (parse_url($base, PHP_URL_SCHEME) ?: 'https').':'.$url;
                }

                return preg_match('#^https?://#i', $url) ? mb_substr($url, 0, 2000) : null;
            }
        }

        return null;
    }

    /**
     * The summary a page publishes for social cards.
     *
     * On a recipe site this is a sentence about the dish; on Instagram or
     * TikTok it is the caption, which is the entire post as far as a
     * logged-out reader is concerned. Either way it is the difference between
     * "there was nothing readable in that" and a card.
     */
    protected function description(string $html): ?string
    {
        $patterns = [
            '#<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']*)["\']#i',
            '#<meta[^>]+content=["\']([^"\']*)["\'][^>]+property=["\']og:description["\']#i',
            '#<meta[^>]+name=["\']twitter:description["\'][^>]+content=["\']([^"\']*)["\']#i',
            '#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)["\']#i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                $text = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                if ($text !== '') {
                    return mb_substr($text, 0, 4000);
                }
            }
        }

        return null;
    }

    /**
     * Remove whole elements, without a regex.
     *
     * `<script>.*?</script>` across a megabyte of markup exhausts PCRE's
     * backtrack limit, and preg_replace signals that by returning null — so a
     * `?? $html` fallback quietly hands the model an entire page of minified
     * JavaScript instead of a recipe. Scanning is linear and cannot fail.
     *
     * @param  list<string>  $tags
     */
    protected function withoutBlocks(string $html, array $tags): string
    {
        foreach ($tags as $tag) {
            $open = '<'.$tag;
            $close = '</'.$tag.'>';
            $out = '';
            $at = 0;

            while (($start = stripos($html, $open, $at)) !== false) {
                // Only a real tag: <scriptable> is not <script>.
                $after = $html[$start + strlen($open)] ?? '';

                if ($after !== '' && ! in_array($after, [' ', '>', "\n", "\r", "\t", '/'], true)) {
                    $out .= substr($html, $at, $start - $at + strlen($open));
                    $at = $start + strlen($open);

                    continue;
                }

                $end = stripos($html, $close, $start);

                $out .= substr($html, $at, $start - $at).' ';

                // An unclosed block runs to the end of the document.
                if ($end === false) {
                    $at = strlen($html);

                    break;
                }

                $at = $end + strlen($close);
            }

            $html = $out.substr($html, $at);
        }

        return $html;
    }

    /**
     * The recipe a page publishes for search engines, if it publishes one.
     *
     * schema.org Recipe is close to universal on recipe sites and is the
     * author's own structured version — ingredients as a list, method as
     * steps — rather than whatever survives stripping tags off the page.
     *
     * @return array<string, mixed>|null
     */
    protected function structuredRecipe(string $html): ?array
    {
        if (! preg_match_all('#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $matches)) {
            return null;
        }

        foreach ($matches[1] as $block) {
            $decoded = json_decode(trim($block), true);

            if (! is_array($decoded)) {
                continue;
            }

            // Sites publish a bare object, a list, or an @graph of them.
            $candidates = $decoded['@graph'] ?? (array_is_list($decoded) ? $decoded : [$decoded]);

            foreach (is_array($candidates) ? $candidates : [] as $node) {
                if (is_array($node) && $this->isRecipeNode($node)) {
                    return $node;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $node */
    protected function isRecipeNode(array $node): bool
    {
        $type = $node['@type'] ?? null;

        return is_array($type) ? in_array('Recipe', $type, true) : $type === 'Recipe';
    }

    protected function title(string $html): ?string
    {
        return preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)
            ? trim(html_entity_decode(strip_tags($m[1])))
            : null;
    }

    protected function text(string $html): string
    {
        // Drop the parts that are never content before stripping tags, or the
        // model reads a page of minified JavaScript.
        $html = $this->withoutBlocks($html, ['script', 'style', 'noscript', 'svg', 'head']);
        $html = preg_replace('#<(br|/p|/div|/li|/tr|/h[1-6])\s*/?>#i', "\n", $html) ?? $html;

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return mb_substr(trim($text), 0, self::MAX_CHARS);
    }
}
