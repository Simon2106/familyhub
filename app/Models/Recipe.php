<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\RecipeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
    'ingredients', 'steps', 'tags', 'is_favourite', 'status', 'error', 'raw_text', 'notes',
])]
class Recipe extends Model
{
    /** @use HasFactory<RecipeFactory> */
    use HasFactory;

    /**
     * Offered as chips when tagging by hand; the model may return others.
     *
     * The older four are kept because rows already carry them — renaming a tag
     * would silently unfile every idea that has it.
     */
    public const SUGGESTED_TAGS = [
        'weeknight', 'quick', 'batch', 'fakeaway', 'kids', 'veggie', 'freezer', 'weekend',
    ];

    /** Rated at or above this, an idea is one worth being sent a week's worth of. */
    public const WELL_LIKED = 4.0;

    /** "Not had for a while" starts here. */
    public const A_WHILE_DAYS = 21;

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

    /** @return HasMany<Meal, $this> */
    public function meals(): HasMany
    {
        return $this->hasMany(Meal::class);
    }

    /** @return HasMany<RecipeRating, $this> */
    public function ratings(): HasMany
    {
        return $this->hasMany(RecipeRating::class);
    }

    /** @return BelongsToMany<MealCollection, $this> */
    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(MealCollection::class, 'meal_collection_recipe');
    }

    /**
     * Times cooked and last cooked, counted off the planner.
     *
     * Nothing records "we ate this" — a day passing with the idea in it is the
     * record. That is why these are queried rather than stored: a meal moved,
     * renamed or deleted changes the history it should, and a counter
     * incremented by a nightly job would drift from the plan for ever.
     *
     * Loaded in bulk by the Ideas grid; the accessors below are for one card.
     *
     * @param  Builder<Recipe>  $query
     */
    public function scopeWithCookedHistory(Builder $query, string $today): void
    {
        $eaten = fn ($q) => $q->where('on', '<', $today);

        $query->withCount(['meals as times_cooked' => $eaten])
            ->withMax(['meals as last_cooked_on' => $eaten], 'on');
    }

    /**
     * Stars from the adults, thumbs from the children, counted separately.
     *
     * @param  Builder<Recipe>  $query
     */
    public function scopeWithOpinions(Builder $query): void
    {
        $query->withAvg(['ratings as stars_average' => fn ($q) => $q->whereNotNull('stars')], 'stars')
            ->withCount(['ratings as stars_count' => fn ($q) => $q->whereNotNull('stars')])
            ->withCount(['ratings as thumbs_up' => fn ($q) => $q->where('thumbs', '>', 0)])
            ->withCount(['ratings as thumbs_down' => fn ($q) => $q->where('thumbs', '<', 0)]);
    }

    /** The adults' average, to one decimal place, or null if nobody has said. */
    public function stars(): ?float
    {
        $average = $this->stars_average ?? $this->ratings()->whereNotNull('stars')->avg('stars');

        return $average === null ? null : round((float) $average, 1);
    }

    /** How the children voted: up, down, or nothing yet. */
    public function kidsVerdict(): ?string
    {
        $up = (int) ($this->thumbs_up ?? $this->ratings()->where('thumbs', '>', 0)->count());
        $down = (int) ($this->thumbs_down ?? $this->ratings()->where('thumbs', '<', 0)->count());

        return match (true) {
            $up === 0 && $down === 0 => null,
            $up > $down => 'up',
            $down > $up => 'down',
            default => 'mixed',
        };
    }

    public function timesCooked(): int
    {
        return (int) ($this->times_cooked ?? $this->meals()->where('on', '<', now()->toDateString())->count());
    }

    public function lastCooked(): ?CarbonImmutable
    {
        $on = $this->last_cooked_on
            ?? $this->meals()->where('on', '<', now()->toDateString())->max('on');

        return $on ? CarbonImmutable::parse($on) : null;
    }

    /** "Never tried" is a real answer, and a different one from "ages ago". */
    public function neverCooked(): bool
    {
        return $this->timesCooked() === 0;
    }

    public function hasTag(string $tag): bool
    {
        return in_array($tag, array_map('mb_strtolower', $this->tags ?? []), true);
    }

    /** @param Builder<Recipe> $query */
    public function scopeTagged(Builder $query, string $tag): void
    {
        // JSON containment differs between MySQL and SQLite, and this list is
        // a handful of short strings per row. LIKE on the encoded array is
        // crude, exact enough for a tag, and works identically on both.
        $query->where('tags', 'like', '%"'.$tag.'"%');
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

    /**
     * The ingredients, every row filled out to the same shape.
     *
     * The rows come from an AI extraction, a shared page, or somebody typing,
     * so a row with no unit and no note is normal. Everything downstream — the
     * dialog, cook mode, the shopping list — is spared guarding four keys by
     * being handed the whole shape here.
     *
     * @return list<array{quantity: float|null, unit: string|null, item: string, note: string|null}>
     */
    public function ingredientList(): array
    {
        return array_values(array_map(
            fn (array $row) => [
                'quantity' => isset($row['quantity']) && $row['quantity'] !== '' ? (float) $row['quantity'] : null,
                'unit' => filled($row['unit'] ?? null) ? (string) $row['unit'] : null,
                'item' => (string) $row['item'],
                'note' => filled($row['note'] ?? null) ? (string) $row['note'] : null,
            ],
            array_filter(
                $this->ingredients ?? [],
                fn ($row) => is_array($row) && filled($row['item'] ?? null),
            ),
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
