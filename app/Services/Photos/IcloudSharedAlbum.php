<?php

namespace App\Services\Photos;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reading a public iCloud shared album.
 *
 * Apple publishes no API for these, but a shared album's public page is itself
 * a small JSON client, and the two calls it makes are stable and
 * unauthenticated: one for the list of photographs, one to turn their ids into
 * URLs that expire in about an hour.
 *
 * That expiry is why the sync downloads rather than links. A wall left idle all
 * afternoon would otherwise start showing broken images an hour in, and a
 * household with no internet would show none at all.
 */
class IcloudSharedAlbum
{
    /** Apple hands albums out across numbered partitions. */
    public const BASE = 'https://p%02d-sharedstreams.icloud.com/%s/sharedstreams/';

    public const TIMEOUT = 30;

    /**
     * The token out of a shared-album URL.
     *
     * The links people copy look like https://www.icloud.com/sharedalbum/#B0abcdef
     * and sometimes carry more after it; the token is the part that matters.
     */
    public function token(string $url): ?string
    {
        if (preg_match('#icloud\.com/sharedalbum/?\#?([A-Za-z0-9]+)#', trim($url), $m)) {
            return $m[1];
        }

        // Somebody may paste just the token.
        return preg_match('#^[A-Za-z0-9]{10,}$#', trim($url)) ? trim($url) : null;
    }

    /**
     * Every photograph in the album, newest first.
     *
     * @return list<array{id: string, url: string, caption: ?string, taken_at: ?string}>
     */
    public function photos(string $albumUrl, int $limit = 200): array
    {
        $token = $this->token($albumUrl);

        if ($token === null) {
            throw new RuntimeException('That does not look like an iCloud shared album link.');
        }

        [$base, $stream] = $this->stream($token);

        $assets = collect($stream['photos'] ?? [])
            // Only the ones with a picture in them; a shared album can hold
            // videos, which a wall has no business autoplaying.
            ->filter(fn ($photo) => filled($photo['derivatives'] ?? null))
            ->sortByDesc(fn ($photo) => $photo['dateCreated'] ?? '')
            ->take($limit)
            ->values();

        if ($assets->isEmpty()) {
            return [];
        }

        $checksums = $assets
            ->map(fn ($photo) => $this->best($photo)['checksum'] ?? null)
            ->filter()
            ->values()
            ->all();

        $urls = $this->assetUrls($base, $checksums);

        return $assets
            ->map(function (array $photo) use ($urls) {
                $best = $this->best($photo);
                $location = $urls[$best['checksum'] ?? ''] ?? null;

                return $location === null ? null : [
                    'id' => (string) ($photo['photoGuid'] ?? $best['checksum']),
                    'url' => $location,
                    'caption' => filled($photo['caption'] ?? null) ? $photo['caption'] : null,
                    'taken_at' => $photo['dateCreated'] ?? null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * What the album is called, as far as Apple will say.
     *
     * The stream carries the owner's name for it. Not always — a shared album
     * made in a hurry has no title — so this is something to show when it is
     * there rather than something to rely on.
     */
    public function name(string $albumUrl): ?string
    {
        $token = $this->token($albumUrl);

        if ($token === null) {
            return null;
        }

        [, $stream] = $this->stream($token);

        $name = trim((string) ($stream['streamName'] ?? ''));

        return $name !== '' ? $name : null;
    }

    /**
     * The album's own listing, following Apple's partition redirect.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    protected function stream(string $token): array
    {
        // Any partition answers; one that does not own the album replies with
        // the number of the one that does.
        $base = sprintf(self::BASE, 1, $token);

        $response = Http::timeout(self::TIMEOUT)
            ->acceptJson()
            ->post($base.'webstream', ['streamCtag' => null]);

        if ($response->status() === 330) {
            $partition = (int) ($response->json('X-Apple-MMe-Host') ?? 0)
                ?: (int) preg_replace('/\D/', '', (string) $response->json('X-Apple-MMe-Host'));

            $base = sprintf(self::BASE, max(1, $partition), $token);

            $response = Http::timeout(self::TIMEOUT)
                ->acceptJson()
                ->post($base.'webstream', ['streamCtag' => null]);
        }

        if ($response->failed()) {
            throw new RuntimeException('iCloud would not open that album. Check the link is still shared publicly.');
        }

        return [$base, (array) $response->json()];
    }

    /**
     * Turn checksums into URLs, in batches Apple will accept.
     *
     * @param  list<string>  $checksums
     * @return array<string, string>
     */
    protected function assetUrls(string $base, array $checksums): array
    {
        $urls = [];

        foreach (array_chunk($checksums, 25) as $chunk) {
            $response = Http::timeout(self::TIMEOUT)
                ->acceptJson()
                ->post($base.'webasseturls', ['photoGuids' => $chunk]);

            if ($response->failed()) {
                continue;
            }

            foreach ((array) $response->json('items', []) as $checksum => $item) {
                $host = $item['url_location'] ?? null;
                $path = $item['url_path'] ?? null;

                if ($host && $path) {
                    $urls[$checksum] = 'https://'.$host.$path;
                }
            }
        }

        return $urls;
    }

    /**
     * The largest derivative on offer.
     *
     * A wall is 1920 across; the thumbnail Apple lists first is 342.
     *
     * @param  array<string, mixed>  $photo
     * @return array<string, mixed>
     */
    protected function best(array $photo): array
    {
        return collect($photo['derivatives'] ?? [])
            ->sortByDesc(fn ($derivative) => (int) ($derivative['fileSize'] ?? 0))
            ->first() ?? [];
    }
}
