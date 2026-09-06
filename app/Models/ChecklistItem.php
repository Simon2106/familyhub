<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['checklist_id', 'done_by_member_id', 'title', 'quantity', 'notes', 'is_done', 'done_at', 'sort_order'])]
class ChecklistItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_done' => 'boolean',
            'done_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Checklist, $this> */
    public function checklist(): BelongsTo
    {
        return $this->belongsTo(Checklist::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'done_by_member_id');
    }

    public function toggle(?Member $member = null): void
    {
        $this->is_done = ! $this->is_done;
        $this->done_at = $this->is_done ? now() : null;
        $this->done_by_member_id = $this->is_done ? $member?->id : null;
        $this->save();
    }
}
