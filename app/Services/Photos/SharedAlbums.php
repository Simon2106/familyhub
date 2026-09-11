<?php

namespace App\Services\Photos;

use Illuminate\Contracts\Container\Container;

/**
 * Picks the driver a link needs.
 *
 * Nobody should have to re-copy a link because Apple moved the sharing pages,
 * so both backends stay. The link itself says which one to use.
 */
class SharedAlbums
{
    public function __construct(protected Container $container) {}

    /** Null when the text is not a shared album link at all. */
    public function for(?string $url): ?SharedAlbum
    {
        $link = SharedAlbumLink::parse($url);

        if ($link === null) {
            return null;
        }

        return $this->container->make(
            $link->isCloudKit() ? CloudKitSharedAlbum::class : IcloudSharedAlbum::class
        );
    }
}
