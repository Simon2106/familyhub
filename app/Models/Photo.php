<?php

namespace App\Models;

use Database\Factories\PhotoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** One photograph the wall may show. */
#[Fillable(['household_id', 'source', 'disk', 'path', 'external_id', 'caption', 'taken_at', 'is_hidden'])]
class Photo extends Model
{
    /** @use HasFactory<PhotoFactory> */
    use HasFactory;

    protected $attributes = ['source' => 'upload', 'is_hidden' => false];

    protected function casts(): array
    {
        return ['taken_at' => 'datetime', 'is_hidden' => 'boolean'];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /** @param Builder<Photo> $query */
    public function scopeShowable(Builder $query): void
    {
        $query->where('is_hidden', false);
    }

    /** Remove the file as well as the row; a hidden photo keeps both. */
    public function forget(): void
    {
        Storage::disk($this->disk)->delete($this->path);

        $this->delete();
    }
}
