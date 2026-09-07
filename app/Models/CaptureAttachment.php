<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['capture_id', 'disk', 'path', 'filename', 'mime', 'size', 'converted_path'])]
class CaptureAttachment extends Model
{
    use HasFactory;

    /** Formats the Messages API accepts as an image block. */
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** @return BelongsTo<Capture, $this> */
    public function capture(): BelongsTo
    {
        return $this->belongsTo(Capture::class);
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf';
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    /** True for images Claude cannot read directly — HEIC, chiefly. */
    public function needsConversion(): bool
    {
        return $this->isImage() && ! in_array($this->mime, self::IMAGE_TYPES, strict: true);
    }

    /** The path to send: the converted copy where one exists. */
    public function readablePath(): string
    {
        return $this->converted_path ?: $this->path;
    }

    public function readableMime(): string
    {
        return $this->converted_path ? 'image/jpeg' : $this->mime;
    }

    public function contents(): ?string
    {
        $disk = Storage::disk($this->disk);
        $path = $this->readablePath();

        return $disk->exists($path) ? $disk->get($path) : null;
    }
}
