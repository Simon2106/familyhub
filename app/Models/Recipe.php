<?php

namespace App\Models;

use Database\Factories\RecipeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A saved meal idea.
 *
 * Deliberately forgiving: everything except the title is optional. Half the
 * things worth saving are a link and a photograph, and a recipe box that
 * refuses those is a recipe box nobody puts anything in.
 */
#[Fillable([
    'household_id', 'title', 'source_kind', 'source_url', 'source_note',
    'hero_image_url', 'image_path', 'image_disk', 'servings',
    'ingredients', 'steps', 'tags', 'is_favourite', 'status', 'error', 'raw_text',
])]
class Recipe extends Model
{
    /** @use HasFactory<RecipeFactory> */
    use HasFactory;

    /** Offered as chips when tagging by hand; the model may return others. */
    public const SUGGESTED_TAGS = ['quick', 'kids', 'veggie', 'batch', 'freezer', 'weekend'];

    /** After this long a still-pending import is stuck, not slow. */
    public const STALL_AFTER_MINUTES = 15;

    protected function casts(): array
    {
        return [
            'ingredients' => 'array',
            'steps' => 'array',
            'tags' => 'array',
            'is_favourite' => 'boolean',
        ];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @param Builder<Recipe> $query */
    public function scopeReady(Builder $query): void
    {
        $query->where('status', 'ready');
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    public function seemsStalled(): bool
    {
        return $this->status === 'pending'
            && $this->updated_at?->lessThan(now()->subMinutes(self::STALL_AFTER_MINUTES));
    }

    /** The picture to show on the card, whichever kind we ended up with. */
    public function imageUrl(): ?string
    {
        if ($this->image_path) {
            return Storage::disk($this->image_disk ?? config('filesystems.default'))->url($this->image_path);
        }

        return $this->hero_image_url;
    }

    /** @return list<array{quantity: float|null, unit: string|null, item: string, note: string|null}> */
    public function ingredientList(): array
    {
        return array_values(array_filter(
            $this->ingredients ?? [],
            fn ($row) => is_array($row) && filled($row['item'] ?? null),
        ));
    }

    public function hasIngredients(): bool
    {
        return $this->ingredientList() !== [];
    }

    /** Where the idea came from, in a few words for the card. */
    public function sourceLabel(): ?string
    {
        if ($this->source_url) {
            return parse_url($this->source_url, PHP_URL_HOST) ?: $this->source_url;
        }

        return match ($this->source_kind) {
            'photo' => 'From a photo',
            'share' => 'Shared to FamilyHub',
            default => null,
        };
    }

    /** One line for the meal grid and the shelf. */
    public function summary(): string
    {
        $parts = array_filter([
            $this->servings ? $this->servings.' servings' : null,
            $this->hasIngredients() ? count($this->ingredientList()).' ingredients' : null,
        ]);

        return implode(' · ', $parts);
    }

    public function markFailed(string $reason): void
    {
        $this->forceFill(['status' => 'failed', 'error' => mb_substr($reason, 0, 1000)])->save();
    }
}
