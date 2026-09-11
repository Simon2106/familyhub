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

    /** The closest two showings of the same favourite are ever allowed to be. */
    public const FAVOURITE_EVERY = 4;

    /** How many times a favourite appears in one pass of the library. */
    public const FAVOURITE_SHOWINGS = 2;

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

        $showing = $recent->shuffle()->take($wanted)
            ->merge($older->shuffle()->take($limit - min($wanted, $recent->count())))
            ->shuffle()
            ->take($limit)
            ->values();

        return $this->favourEach($showing, $all, $limit);
    }

    /**
     * Favourites, more often.
     *
     * A second copy of each favourite, spread through the running order
     * rather than clumped — which is what "more often" means to somebody
     * watching: it comes round again sooner, not twice in a row.
     *
     * Recency already decides most of the mix; this is the family overruling
     * it for the handful of pictures everybody stops to look at.
     *
     * @param  Collection<int, Photo>  $showing
     * @param  Collection<int, Photo>  $all
     * @return Collection<int, Photo>
     */
    protected function favourEach(Collection $showing, Collection $all, int $limit): Collection
    {
        $favourites = $all->filter(fn (Photo $photo) => $photo->is_favourite)->values();

        if ($favourites->isEmpty() || $showing->isEmpty()) {
            return $showing;
        }

        $out = $showing->all();

        foreach ($favourites->shuffle()->values() as $favourite) {
            // A favourite that did not make the shuffle at all still has to
            // appear more often than the rest, so it is topped up to the same
            // number of showings as one that did. Otherwise "favourite" would
            // mean "slightly more likely", which is not what a heart says.
            while ($this->timesIn($out, $favourite) < self::FAVOURITE_SHOWINGS) {
                array_splice($out, $this->widestGapIn($out, $favourite), 0, [$favourite]);
            }
        }

        return collect($out)
            ->take($limit + $favourites->count() * self::FAVOURITE_SHOWINGS)
            ->values();
    }

    /** @param list<Photo> $showing */
    protected function timesIn(array $showing, Photo $photo): int
    {
        return count(array_filter($showing, fn (Photo $each) => $each->id === $photo->id));
    }

    /**
     * The point furthest from every showing this photograph already has.
     *
     * Inserting at a fixed offset is how two copies end up side by side: the
     * ones already in the running order are wherever the shuffle put them.
     * The middle of the widest gap is the only placement that cannot be
     * adjacent to one unless there is nowhere else to go.
     *
     * @param  list<Photo>  $showing
     */
    protected function widestGapIn(array $showing, Photo $photo): int
    {
        $at = [];

        foreach ($showing as $index => $each) {
            if ($each->id === $photo->id) {
                $at[] = $index;
            }
        }

        if ($at === []) {
            return intdiv(count($showing), 2);
        }

        // The ends count as edges of a gap, so a lone copy at the front puts
        // its second half-way down the rest rather than immediately after.
        $edges = [-1, ...$at, count($showing)];
        $best = 0;
        $widest = -1;

        for ($i = 0; $i < count($edges) - 1; $i++) {
            $gap = $edges[$i + 1] - $edges[$i];

            if ($gap > $widest) {
                $widest = $gap;
                $best = $edges[$i] + intdiv($gap, 2);
            }
        }

        return max(0, min(count($showing), $best));
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
