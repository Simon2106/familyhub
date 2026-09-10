<?php

namespace App\Services;

use App\Models\Household;
use App\Models\Photo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * The photographs the screensaver shows.
 *
 * Reads the table rather than the folder, because a caption and a "not that
 * one" have nowhere to live in a filename. Loose files already in the folder
 * are adopted on sight, so a household that has been dropping photographs into
 * a directory keeps working without being asked to do anything.
 *
 * Recent ones are favoured but not exclusively: a wall that only ever showed
 * this month would quietly retire every photograph the family has, and one
 * that shuffled everything evenly would show this summer once a year.
 */
class PhotoLibrary
{
    protected const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'avif'];

    /** How much of a showing is drawn from the last few months. */
    public const RECENT_SHARE = 0.6;

    public const RECENT_MONTHS = 6;

    /**
     * @return list<string> public URLs, shuffled so the wall does not open on
     *                      the same photograph every time it goes idle
     */
    public function urls(int $limit = 200, ?Household $household = null): array
    {
        return $this->photos($limit, $household)->map(fn (Photo $photo) => $photo->url())->all();
    }

    /**
     * The photographs themselves, for anywhere that wants the caption too.
     *
     * @return Collection<int, Photo>
     */
    public function photos(int $limit = 200, ?Household $household = null): Collection
    {
        $household ??= Household::current();

        $this->adoptLooseFiles($household);

        $all = Photo::query()
            ->where('household_id', $household->id)
            ->showable()
            ->get();

        if ($all->isEmpty()) {
            return collect();
        }

        $cutoff = now()->subMonths(self::RECENT_MONTHS);

        [$recent, $older] = $all->partition(
            fn (Photo $photo) => $photo->taken_at?->greaterThan($cutoff) ?? false,
        );

        // Recent first and older behind it, each shuffled: the mix is the
        // point, not the order within either half.
        $wanted = (int) round($limit * self::RECENT_SHARE);

        return $recent->shuffle()->take($wanted)
            ->merge($older->shuffle()->take($limit - min($wanted, $recent->count())))
            ->shuffle()
            ->take($limit)
            ->values();
    }

    /**
     * Register anything sitting in the photos folder that has no row yet.
     *
     * The folder was the whole feature before this table existed, and a
     * household that has been using it should not have to re-upload.
     */
    public function adoptLooseFiles(?Household $household = null): int
    {
        $household ??= Household::current();

        $diskName = (string) config('familyhub.photos.disk');
        $disk = Storage::disk($diskName);
        $path = (string) config('familyhub.photos.path');

        if (! $disk->directoryExists($path)) {
            return 0;
        }

        $known = Photo::where('household_id', $household->id)
            ->where('disk', $diskName)
            ->pluck('path')
            ->all();

        $adopted = 0;

        foreach ($disk->files($path) as $file) {
            if (in_array($file, $known, true) || ! $this->isImage($file)) {
                continue;
            }

            Photo::create([
                'household_id' => $household->id,
                'source' => 'folder',
                'disk' => $diskName,
                'path' => $file,
                // The file's own date is the best guess at when it was taken.
                'taken_at' => $disk->lastModified($file)
                    ? now()->setTimestamp($disk->lastModified($file))
                    : null,
            ]);

            $adopted++;
        }

        return $adopted;
    }

    protected function isImage(string $file): bool
    {
        return in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }
}
