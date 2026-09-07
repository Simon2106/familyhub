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

    /** @return array{title: ?string, text: string, image: ?string} */
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
        $html = preg_replace('#<(script|style|noscript|svg|head)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<(br|/p|/div|/li|/tr|/h[1-6])\s*/?>#i', "\n", $html) ?? $html;

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return mb_substr(trim($text), 0, self::MAX_CHARS);
    }
}
