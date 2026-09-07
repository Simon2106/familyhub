<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The brief calls this "List"; `list` is a reserved word in PHP and cannot be a
 * class name, so the model is Checklist over the `checklists` table.
 */
#[Fillable(['household_id', 'name', 'type', 'icon', 'colour', 'sort_order', 'is_home_list'])]
class Checklist extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['is_home_list' => 'boolean'];
    }

    /**
     * The household to-do list shown on the wall's home view.
     *
     * Created on demand so a fresh install has somewhere to put the first
     * to-do without anyone visiting settings first.
     */
    public static function home(?Household $household = null): self
    {
        $household ??= Household::current();

        return static::firstOrCreate(
            ['household_id' => $household->id, 'is_home_list' => true],
            ['name' => 'To do', 'type' => 'todo'],
        );
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return HasMany<ChecklistItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('is_done')->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<ChecklistItem, $this> */
    public function openItems(): HasMany
    {
        return $this->items()->where('is_done', false);
    }

    /** @return HasMany<ChecklistItem, $this> */
    public function doneItems(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)
            ->where('is_done', true)
            ->orderByDesc('done_at');
    }
}
