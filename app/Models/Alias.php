<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Another name something answers to in an event title — "SW" for Simon,
 * "IAAS" for Ice and a Slice.
 */
#[Fillable(['alias', 'kind'])]
class Alias extends Model
{
    use HasFactory;

    protected $table = 'aliases';

    /** @return MorphTo<Model, $this> */
    /** @var array<string, mixed> */
    protected $attributes = ['kind' => 'name'];

    /** @param Builder<Alias> $query */
    public function scopeNames($query): void
    {
        $query->where('kind', 'name');
    }

    /** @param Builder<Alias> $query */
    public function scopeDomains($query): void
    {
        $query->where('kind', 'domain');
    }

    public function aliasable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Aliases are compared case-insensitively, so they are stored trimmed. */
    public function setAliasAttribute(string $value): void
    {
        $this->attributes['alias'] = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }
}
