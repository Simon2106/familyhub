<?php

namespace App\Models;

use Database\Factories\RewardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** Something a child can save up for. */
#[Fillable(['household_id', 'name', 'cost', 'image_path', 'image_disk', 'is_active', 'sort_order'])]
class Reward extends Model
{
    /** @use HasFactory<RewardFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = ['cost' => 0, 'is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function imageUrl(): ?string
    {
        return $this->image_path
            ? Storage::disk($this->image_disk ?? config('filesystems.default'))->url($this->image_path)
            : null;
    }

    /** @param Builder<Reward> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
