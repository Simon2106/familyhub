<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Shrink the wall artwork to what the kiosk actually needs.
 *
 * The avatars arrive ~1250px and about a megabyte each, and the wall draws
 * them at 72px. The background arrives as a two-megabyte PNG for a panel
 * that shows 1920x1080 of it. A Pi in a kitchen should not be decoding
 * seven megabytes of image to paint one screen.
 *
 * Run once, and again whenever somebody drops new artwork in. The outputs
 * are committed: a kiosk that has to run an image pipeline before it can
 * draw a face is a kiosk that shows nothing on a fresh deploy.
 */
class BuildWallImages extends Command
{
    protected $signature = 'familyhub:build-images {--force : Rebuild even where the webp is newer}';

    protected $description = 'Shrink the wall artwork to the sizes the kiosk actually serves';

    /** What the wall draws, and the same again for a 2x panel. */
    public const SIZES = [144, 288];

    public const QUALITY = 88;

    public function handle(): int
    {
        $dir = public_path('images/avatars');

        if (! File::isDirectory($dir)) {
            $this->components->error("There is no {$dir}.");

            return self::FAILURE;
        }

        $built = 0;

        foreach (File::glob($dir.'/*.png') as $source) {
            $slug = pathinfo($source, PATHINFO_FILENAME);

            foreach (self::SIZES as $size) {
                $target = "{$dir}/{$slug}-{$size}.webp";

                if (! $this->option('force') && File::exists($target) && File::lastModified($target) >= File::lastModified($source)) {
                    continue;
                }

                $this->write($source, $target, $size);

                $built++;

                $this->components->twoColumnDetail(
                    basename($target),
                    $this->human((int) File::size($target)),
                );
            }
        }

        $built += $this->background();

        $this->components->info($built === 0 ? 'Everything was already built.' : "Built {$built}.");

        return self::SUCCESS;
    }

    /**
     * The wall's backdrop, as a webp.
     *
     * Kept at its full 1920 width: the CSS covers with it, so the whole
     * width is used at 1920x1080 and cropping it to the visible band would
     * break any other shape of screen. The saving is the format, not the
     * pixels — a PNG of a smooth purple gradient is about ten times the
     * size of the same thing as a webp.
     */
    protected function background(): int
    {
        $source = public_path('images/wall-bg.png');
        $target = public_path('images/wall-bg.webp');

        if (! File::exists($source)) {
            return 0;
        }

        if (! $this->option('force') && File::exists($target) && File::lastModified($target) >= File::lastModified($source)) {
            return 0;
        }

        $image = imagecreatefrompng($source);

        if ($image === false) {
            throw new \RuntimeException("Could not read {$source}.");
        }

        imagewebp($image, $target, self::QUALITY);
        imagedestroy($image);

        $this->components->twoColumnDetail(
            basename($target),
            $this->human((int) File::size($source)).' → '.$this->human((int) File::size($target)),
        );

        return 1;
    }

    /**
     * Centre-crop to a square, then resize.
     *
     * The crop is why this exists rather than a plain resize: simon.png is
     * 1312x1199, and a non-square source squashed into a round frame gives
     * one person a wider face than everybody else.
     */
    protected function write(string $source, string $target, int $size): void
    {
        $image = imagecreatefrompng($source);

        if ($image === false) {
            throw new \RuntimeException("Could not read {$source}.");
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $side = min($width, $height);

        $canvas = imagecreatetruecolor($size, $size);

        // The artwork is cut out on transparency, and the wall puts it on a
        // coloured disc — so the alpha has to survive the resize.
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        imagecopyresampled(
            $canvas,
            $image,
            0, 0,
            (int) (($width - $side) / 2),
            (int) (($height - $side) / 2),
            $size, $size,
            $side, $side,
        );

        imagewebp($canvas, $target, self::QUALITY);

        imagedestroy($canvas);
        imagedestroy($image);
    }

    protected function human(int $bytes): string
    {
        return $bytes > 1024 * 1024
            ? round($bytes / 1024 / 1024, 1).' MB'
            : round($bytes / 1024).' KB';
    }
}
