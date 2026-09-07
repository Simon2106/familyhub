<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thing that was read: an attachment, or the message body.
 */
#[Fillable(['capture_id', 'label', 'kind', 'summary', 'input_tokens', 'output_tokens'])]
class CaptureSource extends Model
{
    use HasFactory;

    /** @return BelongsTo<Capture, $this> */
    public function capture(): BelongsTo
    {
        return $this->belongsTo(Capture::class);
    }

    /** @return HasMany<CaptureItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CaptureItem::class);
    }

    public function isAttachment(): bool
    {
        return $this->kind === 'attachment';
    }

    /** How the card names it: a filename speaks for itself, a body does not. */
    public function shortLabel(): string
    {
        return $this->isAttachment() ? $this->label : 'the message';
    }
}
