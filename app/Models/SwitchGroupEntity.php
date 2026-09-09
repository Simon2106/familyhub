<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One switch's membership of a group. */
#[Fillable(['switch_group_id', 'entity_id', 'name', 'sort_order'])]
class SwitchGroupEntity extends Model
{
    public $timestamps = false;

    /** @var array<string, mixed> */
    protected $attributes = ['sort_order' => 0];

    /** @return BelongsTo<SwitchGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(SwitchGroup::class, 'switch_group_id');
    }

    public function domain(): string
    {
        return str_contains($this->entity_id, '.') ? explode('.', $this->entity_id)[0] : '';
    }

    /** The name it had when it was chosen, so a group reads with the Pi down. */
    public function title(): string
    {
        return $this->name ?: $this->entity_id;
    }
}
