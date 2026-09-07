<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A child asking to spend their points, and what happened next. */
#[Fillable([
    'household_id', 'member_id', 'reward_id', 'name', 'cost',
    'status', 'requested_at', 'decided_at', 'decided_by_member_id',
])]
class Redemption extends Model
{
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<Reward, $this> */
    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isGranted(): bool
    {
        return $this->status === 'granted';
    }

    /** @param Builder<Redemption> $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', 'pending');
    }
}
