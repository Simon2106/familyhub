<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/**
 * Lists the images used by the idle screensaver.
 *
 * Backed by whichever disk FAMILYHUB_PHOTOS_DISK points at, so the same code
 * serves a local folder in development and an S3 bucket in production.
 */
class PhotoLibrary
{
    protected const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'avif'];

    /**
     * @return list<string> public URLs, shuffled so the wall does not show the
     *                      same opening photo every time it goes idle
     */
    public function urls(int $limit = 200): array
    {
        $disk = Storage::disk(config('familyhub.photos.disk'));
        $path = (string) config('familyhub.photos.path');

        // A missing folder is the normal state before anyone adds photos.
        if (! $disk->directoryExists($path)) {
            return [];
        }

        $files = collect($disk->files($path))
            ->filter(fn (string $file) => in_array(
                strtolower(pathinfo($file, PATHINFO_EXTENSION)),
                self::EXTENSIONS,
                strict: true,
            ))
            ->shuffle()
            ->take($limit)
            ->map(fn (string $file) => $disk->url($file))
            ->values();

        return $files->all();
    }
}
