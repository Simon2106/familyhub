<?php

namespace App\Services\Photos;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * A public iCloud shared album, read through CloudKit Web Services.
 *
 * What photos.icloud.com/shared/album/<key> is doing underneath. No login and
 * no Apple ID: the album's own key is the credential, and it buys a
 * short-lived anonymous token that the rest of the calls carry.
 *
 * Two requests before anything can be listed:
 *
 *   1. resolve the key on ckdatabasews.icloud.com. The reply carries the
 *      shared zone, the album's title, and an anonymous access token — and
 *      the response *headers* carry which shard the album lives on. Guessing
 *      the shard does not work; it is per-album.
 *   2. query that shard's shared database for the assets.
 *
 * Photographs come back as pairs: a CPLAsset holding the rendered JPEGs and
 * the dates, and a CPLMaster holding what was actually uploaded — its type,
 * its filename, and the original file. The asset points at its master, and
 * both are needed: the JPEG to show, the master to know whether this is a
 * photograph at all.
 */
class CloudKitSharedAlbum implements SharedAlbum
{
    /** What the web app sends. Apple rejects a request with no build number. */
    public const CLIENT_BUILD = '2632BuildBeta16';

    public const CONTAINER = 'com.apple.photos.cloud';

    /** One page. Apple caps this well below anything a family album holds. */
    public const PAGE_SIZE = 200;

    /** A bound on paging, so a broken continuation marker cannot loop. */
    public const MAX_PAGES = 25;

    /** Newest first; the fallback is for albums Apple has not indexed that way. */
    public const RECORD_TYPES = ['CPLAssetAndMasterByAddedDate', 'CPLAssetAndMasterByAssetDate'];

    /** Resolved once per run and thrown away: the token is short-lived. */
    protected ?array $resolved = null;

    protected ?string $resolvedFor = null;

    /**
     * Every photograph in the album, newest first.
     *
     * @return list<array{id: string, url: string, caption: ?string, taken_at: ?string}>
     */
    public function photos(string $albumUrl, int $limit = 200): array
    {
        $link = $this->link($albumUrl);
        $share = $this->resolve($link->key);

        $masters = [];
        $assets = [];

        foreach ($this->records($link->key, $share) as $record) {
            if (($record['recordType'] ?? null) === 'CPLMaster') {
                $masters[$record['recordName']] = $record;

                continue;
            }

            if (($record['recordType'] ?? null) === 'CPLAsset') {
                $assets[] = $record;
            }
        }

        $photos = [];

        foreach ($assets as $asset) {
            $photo = $this->toPhoto($asset, $masters);

            if ($photo !== null) {
                $photos[] = $photo;
            }
        }

        // Newest first, the way the album reads on a phone.
        usort($photos, fn (array $a, array $b) => ($b['taken_at'] ?? '') <=> ($a['taken_at'] ?? ''));

        return array_slice($photos, 0, $limit);
    }

    /** The album's own title, as its owner named it. */
    public function name(string $albumUrl): ?string
    {
        $share = $this->resolve($this->link($albumUrl)->key);

        $title = trim((string) data_get($share, 'share.fields.cloudkit\.title.value', ''));

        if ($title === '') {
            // The key is sometimes under a literal dotted name rather than a
            // nested path, depending on how the JSON was decoded.
            $title = trim((string) (($share['share']['fields']['cloudkit.title']['value'] ?? '')));
        }

        return $title !== '' ? $title : null;
    }

    protected function link(string $albumUrl): SharedAlbumLink
    {
        $link = SharedAlbumLink::parse($albumUrl);

        if (! $link || ! $link->isCloudKit()) {
            throw new RuntimeException('That is not a photos.icloud.com shared album link.');
        }

        return $link;
    }

    /**
     * Trade the album key for a zone, a shard and a token.
     *
     * Cached for the life of this object only. The token expires quickly, so
     * every sync run resolves again rather than keeping one around.
     *
     * @return array<string, mixed>
     */
    public function resolve(string $key): array
    {
        if ($this->resolved !== null && $this->resolvedFor === $key) {
            return $this->resolved;
        }

        $url = 'https://ckdatabasews.icloud.com/database/1/'.self::CONTAINER.'/production/public/records/resolve'
            .'?'.http_build_query([
                'remapEnums' => 'true',
                'getCurrentSyncToken' => 'true',
                'clientBuildNumber' => self::CLIENT_BUILD,
                'clientMasteringNumber' => self::CLIENT_BUILD,
                'sharing_url_key' => $key,
            ]);

        try {
            $response = $this->request()->withBody(
                json_encode(['shortGUIDs' => [['value' => $key]]]),
                'text/plain',
            )->post($url);
        } catch (Throwable $e) {
            throw new RuntimeException('That album could not be reached: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'That album answered '.$response->status().'. Check the link is still shared publicly.'
            );
        }

        $result = $response->json('results.0');

        if (! is_array($result) || blank($result['zoneID'] ?? null)) {
            throw new RuntimeException('That album did not look like a shared album. Check the link.');
        }

        // The shard is per-album and only the headers say which. A request to
        // the wrong one comes back empty rather than failing, which is a much
        // worse way to find out.
        $partition = trim((string) $response->header('x-apple-user-partition'));

        $this->resolvedFor = $key;

        return $this->resolved = [
            'zoneID' => $result['zoneID'],
            'share' => $result['share'] ?? [],
            'token' => $this->tokenFrom($response, $result),
            'host' => $partition !== ''
                ? 'https://p'.$partition.'-ckdatabasews.icloud.com'
                : 'https://ckdatabasews.icloud.com',
        ];
    }

    /**
     * The anonymous access token.
     *
     * Apple has returned this both as a response header and in the body; both
     * are read, header first, because a header is the documented place and the
     * body key has moved before.
     *
     * @param  array<string, mixed>  $result
     */
    protected function tokenFrom(mixed $response, array $result): string
    {
        foreach (['X-CloudKit-PublicAccess-AuthToken', 'x-cloudkit-publicaccess-authtoken'] as $header) {
            $value = trim((string) $response->header($header));

            if ($value !== '') {
                return $value;
            }
        }

        foreach ([
            'anonymousPublicAccess.token',
            'publicAccessAuthToken',
            'share.publicAccessAuthToken',
        ] as $path) {
            $value = trim((string) data_get($result, $path, ''));

            if ($value !== '') {
                return $value;
            }
        }

        throw new RuntimeException('That album did not hand out an access token. It may no longer be shared.');
    }

    /**
     * Every record in the shared zone, paged.
     *
     * @param  array<string, mixed>  $share
     * @return list<array<string, mixed>>
     */
    protected function records(string $key, array $share): array
    {
        foreach (self::RECORD_TYPES as $recordType) {
            $records = $this->pagesOf($key, $share, $recordType);

            if ($records !== []) {
                return $records;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $share
     * @return list<array<string, mixed>>
     */
    protected function pagesOf(string $key, array $share, string $recordType): array
    {
        $records = [];
        $marker = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $body = [
                'query' => ['recordType' => $recordType],
                'zoneID' => $share['zoneID'],
                'resultsLimit' => self::PAGE_SIZE,
            ];

            if ($marker !== null) {
                $body['continuationMarker'] = $marker;
            }

            $url = $share['host'].'/database/1/'.self::CONTAINER.'/production/shared/records/query'
                .'?'.http_build_query([
                    'remapEnums' => 'true',
                    'getCurrentSyncToken' => 'true',
                    'sharing_url_key' => $key,
                    'publicAccessAuthToken' => $share['token'],
                    'clientBuildNumber' => self::CLIENT_BUILD,
                    'clientMasteringNumber' => self::CLIENT_BUILD,
                    'clientId' => (string) Str::uuid(),
                ]);

            try {
                $response = $this->request()->withBody(json_encode($body), 'text/plain')->post($url);
            } catch (Throwable $e) {
                throw new RuntimeException('That album could not be read: '.$e->getMessage(), previous: $e);
            }

            if ($response->failed()) {
                // A record type this album is not indexed by answers with an
                // error rather than an empty list; the caller tries the next.
                return [];
            }

            $page_records = $response->json('records') ?? [];

            if (! is_array($page_records)) {
                return $records;
            }

            $records = [...$records, ...$page_records];
            $marker = $response->json('continuationMarker');

            if (blank($marker) || $page_records === []) {
                break;
            }
        }

        return $records;
    }

    /**
     * One photograph, from its asset and the master behind it.
     *
     * Null for anything that is not a still photograph, or has no JPEG to
     * show — a shared album can hold videos, and a wall has no business
     * autoplaying one.
     *
     * @param  array<string, mixed>  $asset
     * @param  array<string, array<string, mixed>>  $masters
     * @return array{id: string, url: string, caption: ?string, taken_at: ?string}|null
     */
    protected function toPhoto(array $asset, array $masters): ?array
    {
        $master = $masters[data_get($asset, 'fields.masterRef.value.recordName')] ?? null;

        if ($master !== null && ! $this->isStill($master)) {
            return null;
        }

        $download = $this->jpeg($asset) ?? $this->jpeg($master ?? []);

        if ($download === null) {
            return null;
        }

        $taken = data_get($asset, 'fields.assetDate.value')
            ?? data_get($asset, 'fields.addedDate.value');

        return [
            'id' => (string) $asset['recordName'],
            'url' => (string) $download,
            // A shared album's captions live somewhere this API does not
            // return, so the filename is not pressed into service as one —
            // "IMG_5235" under a photograph is worse than nothing.
            'caption' => null,
            'taken_at' => $taken ? CarbonImmutable::createFromTimestampMs((int) $taken)->toIso8601String() : null,
        ];
    }

    /**
     * The largest rendition that is genuinely a JPEG.
     *
     * The names lie. `resJPEGFullRes` on a photograph taken as HEIC is a HEIC
     * — Apple's "full res JPEG" means "the full-size rendition", not the
     * format — and a wall handed one of those shows a broken image. The
     * sibling `…FileType` field is the only thing that says what the bytes
     * actually are, so it, rather than the field name, decides.
     *
     * @param  array<string, mixed>  $record
     */
    protected function jpeg(array $record): ?string
    {
        // Biggest first. The medium rendition is 2048 on its long edge, which
        // is still more than the wall's 1920.
        foreach (['resJPEGFull', 'resJPEGMed', 'resJPEGThumb'] as $rendition) {
            $url = data_get($record, "fields.{$rendition}Res.value.downloadURL");
            $type = mb_strtolower((string) data_get($record, "fields.{$rendition}FileType.value"));

            // No type at all is the older shape of the reply, where these
            // really were all JPEGs.
            if (filled($url) && ($type === '' || Str::contains($type, 'jpeg'))) {
                return (string) $url;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $master */
    protected function isStill(array $master): bool
    {
        $type = mb_strtolower((string) data_get($master, 'fields.itemType.value'));

        if ($type === '') {
            return true;
        }

        return ! Str::contains($type, ['movie', 'video', 'mpeg', 'quicktime']);
    }

    protected function request(): PendingRequest
    {
        return Http::timeout(30)
            ->connectTimeout(10)
            ->withHeaders([
                // CloudKit checks these; without them the resolve is refused.
                'origin' => 'https://photos.icloud.com',
                'referer' => 'https://photos.icloud.com/',
            ]);
    }
}
