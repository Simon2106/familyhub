<?php

namespace App\Services\Photos;

/**
 * A public shared album somebody has pasted a link to.
 *
 * Apple has two of these live at once — the old sharedstreams API behind
 * www.icloud.com/sharedalbum/#B0…, and CloudKit Web Services behind
 * photos.icloud.com/shared/album/… — and which one a household is on depends
 * only on when they copied the link. Both answer the same two questions, so
 * the sync asks them through here and never has to care.
 */
interface SharedAlbum
{
    /**
     * Every photograph in the album, newest first.
     *
     * Videos are left out: a wall has no business autoplaying one.
     *
     * @return list<array{id: string, url: string, caption: ?string, taken_at: ?string}>
     */
    public function photos(string $albumUrl, int $limit = 200): array;

    /** What the album's owner called it, where they called it anything. */
    public function name(string $albumUrl): ?string;
}
