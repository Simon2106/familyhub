<?php

namespace App\Services\Capture;

use App\Models\CaptureAttachment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Imagick;
use Throwable;

/**
 * Gets an attachment into a form the Messages API will accept.
 *
 * Two things need doing. iPhones photograph in HEIC, which the API does not
 * read, so those are converted to JPEG once and the copy kept. And large photos
 * are downscaled: a 12MP original is far more base64 than the model needs to
 * read a letter, and the request has a hard size limit.
 */
class AttachmentPreparer
{
    /** The API's request ceiling is 32MB; stay well inside it per attachment. */
    public const MAX_BYTES = 4_500_000;

    /** Long edge in pixels. Plenty to read a page of A4. */
    public const MAX_EDGE = 2000;

    /**
     * @return array{0: string, 1: string}|null base64 data and media type
     */
    public function prepare(CaptureAttachment $attachment): ?array
    {
        if ($attachment->needsConversion()) {
            $this->convert($attachment);
        }

        $contents = $attachment->contents();

        if ($contents === null || $contents === '') {
            return null;
        }

        $mime = $attachment->readableMime();

        if ($mime === 'application/pdf') {
            // A PDF cannot be downscaled here; an oversized one is skipped
            // rather than sent to fail.
            return strlen($contents) > self::MAX_BYTES ? null : [base64_encode($contents), $mime];
        }

        if (! in_array($mime, CaptureAttachment::IMAGE_TYPES, strict: true)) {
            return null;
        }

        if (strlen($contents) > self::MAX_BYTES) {
            $shrunk = $this->downscale($contents);

            if ($shrunk === null) {
                return null;
            }

            [$contents, $mime] = $shrunk;
        }

        return [base64_encode($contents), $mime];
    }

    /** Converts a HEIC (or other unreadable image) to JPEG alongside the original. */
    protected function convert(CaptureAttachment $attachment): void
    {
        if ($attachment->converted_path) {
            return;
        }

        $disk = Storage::disk($attachment->disk);

        if (! $disk->exists($attachment->path)) {
            return;
        }

        try {
            $image = new Imagick;
            $image->readImageBlob($disk->get($attachment->path));
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(85);
            // HEIC frequently carries an orientation flag rather than rotated
            // pixels; a sideways letter is much harder to read.
            $image->autoOrient();

            $path = $attachment->path.'.converted.jpg';
            $disk->put($path, $image->getImageBlob());
            $image->destroy();

            $attachment->forceFill(['converted_path' => $path])->save();
        } catch (Throwable $e) {
            // Not fatal: the capture proceeds with whatever else it has.
            Log::warning('Could not convert capture attachment', [
                'attachment' => $attachment->id,
                'mime' => $attachment->mime,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return array{0: string, 1: string}|null */
    protected function downscale(string $contents): ?array
    {
        try {
            $image = new Imagick;
            $image->readImageBlob($contents);
            $image->autoOrient();

            if (max($image->getImageWidth(), $image->getImageHeight()) > self::MAX_EDGE) {
                $image->scaleImage(self::MAX_EDGE, self::MAX_EDGE, bestfit: true);
            }

            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(80);

            $blob = $image->getImageBlob();
            $image->destroy();

            return strlen($blob) > self::MAX_BYTES ? null : [$blob, 'image/jpeg'];
        } catch (Throwable $e) {
            Log::warning('Could not downscale capture attachment', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
