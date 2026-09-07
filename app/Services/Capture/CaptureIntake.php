<?php

namespace App\Services\Capture;

use App\Jobs\ProcessCaptureJob;
use App\Models\Capture;
use App\Models\CaptureAttachment;
use App\Models\Household;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The one way a capture is created, whatever it came from.
 *
 * Every entry point — inbound email, an upload, pasted text, a share, a URL —
 * lands here and then runs the same job, so they cannot drift apart.
 */
class CaptureIntake
{
    public function __construct(protected UrlFetcher $urls) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<UploadedFile|array{name: string, mime: string, contents: string}>  $files
     */
    public function create(string $source, array $attributes = [], array $files = [], bool $dispatch = true): Capture
    {
        $household = $attributes['household'] ?? Household::current();

        $capture = Capture::create([
            'household_id' => $household->id,
            'source' => in_array($source, Capture::SOURCES, strict: true) ? $source : 'text',
            'status' => 'pending',
            'subject' => $this->trim($attributes['subject'] ?? null, 250),
            'sender' => $this->trim($attributes['sender'] ?? null, 250),
            'body_text' => $attributes['body_text'] ?? null,
            'raw_payload' => isset($attributes['raw_payload'])
                ? (is_string($attributes['raw_payload']) ? $attributes['raw_payload'] : json_encode($attributes['raw_payload']))
                : null,
        ]);

        foreach ($files as $file) {
            $this->attach($capture, $file);
        }

        if ($dispatch) {
            ProcessCaptureJob::dispatch($capture);
        }

        return $capture;
    }

    /** Fetches a URL, then captures its readable text. */
    public function fromUrl(string $url, ?Household $household = null, bool $dispatch = true): Capture
    {
        $page = $this->urls->fetch($url);

        return $this->create('url', [
            'household' => $household ?? Household::current(),
            'subject' => $page['title'] ?? $url,
            'sender' => parse_url($url, PHP_URL_HOST) ?: null,
            'body_text' => trim(($page['text'] ?? '')."\n\nSource: ".$url),
            'raw_payload' => $url,
        ], dispatch: $dispatch);
    }

    /** @param UploadedFile|array{name: string, mime: string, contents: string} $file */
    protected function attach(Capture $capture, UploadedFile|array $file): CaptureAttachment
    {
        $disk = config('filesystems.default');
        $directory = 'captures/'.$capture->id;

        if ($file instanceof UploadedFile) {
            $name = $file->getClientOriginalName() ?: 'upload';
            $mime = $file->getMimeType() ?: 'application/octet-stream';
            $path = $file->store($directory, $disk);
            $size = $file->getSize() ?: 0;
        } else {
            $name = $file['name'];
            $mime = $file['mime'];
            $path = $directory.'/'.Str::uuid().'-'.Str::slug(pathinfo($name, PATHINFO_FILENAME)).
                ($ext = pathinfo($name, PATHINFO_EXTENSION) ? '.'.pathinfo($name, PATHINFO_EXTENSION) : '');
            Storage::disk($disk)->put($path, $file['contents']);
            $size = strlen($file['contents']);
        }

        return CaptureAttachment::create([
            'capture_id' => $capture->id,
            'disk' => $disk,
            'path' => $path,
            'filename' => mb_substr($name, 0, 250),
            'mime' => $mime,
            'size' => $size,
        ]);
    }

    protected function trim(?string $value, int $length): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : mb_substr($value, 0, $length);
    }
}
