<?php

namespace App\Services\Photos;

/**
 * The two shapes an iCloud shared album link comes in.
 *
 * Apple moved the sharing pages from www.icloud.com/sharedalbum/#B0… to
 * photos.icloud.com/shared/album/<key>, and the two are served by entirely
 * different backends — the old one by the sharedstreams JSON API, the new by
 * CloudKit Web Services. Which link somebody pastes decides which driver runs,
 * so telling them apart is the first thing that happens.
 *
 * Links already saved keep working: nobody should have to re-copy a link
 * because Apple redecorated.
 */
class SharedAlbumLink
{
    public const LEGACY = 'sharedstreams';

    public const CLOUDKIT = 'cloudkit';

    private function __construct(
        public readonly string $kind,
        public readonly string $key,
        public readonly string $url,
    ) {}

    /** Null when it is not a shared album link at all. */
    public static function parse(?string $url): ?self
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        // photos.icloud.com/shared/album/<key>, with or without a scheme, and
        // tolerating the trailing slash a copied link often carries.
        if (preg_match('#photos\.icloud\.com/shared/album/([A-Za-z0-9_\-]+)#i', $url, $found)) {
            return new self(self::CLOUDKIT, $found[1], $url);
        }

        // www.icloud.com/sharedalbum/#B0abcdef — the key is after the hash.
        if (preg_match('#icloud\.com/sharedalbum/?\#?([A-Za-z0-9]+)#i', $url, $found)) {
            return new self(self::LEGACY, $found[1], $url);
        }

        return null;
    }

    public function isCloudKit(): bool
    {
        return $this->kind === self::CLOUDKIT;
    }

    /** What to call it in a sentence somebody reads in /admin. */
    public function kindLabel(): string
    {
        return $this->isCloudKit() ? 'photos.icloud.com link' : 'older icloud.com link';
    }
}
