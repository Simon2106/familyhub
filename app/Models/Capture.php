<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something that arrived and might contain dates: a forwarded newsletter, a
 * photo of a letter, a pasted block of text.
 *
 * Nothing here reaches a calendar until a person accepts it.
 */
#[Fillable(['household_id', 'source', 'status', 'subject', 'sender', 'body_text', 'raw_payload', 'summary', 'error', 'processed_at'])]
class Capture extends Model
{
    use HasFactory;

    public const SOURCES = ['email', 'photo', 'pdf', 'text', 'url', 'share'];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return HasMany<CaptureAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(CaptureAttachment::class);
    }

    /** @return HasMany<CaptureItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CaptureItem::class)->orderBy('start_at')->orderBy('id');
    }

    /** @return HasMany<CaptureItem, $this> */
    public function pendingItems(): HasMany
    {
        return $this->items()->where('status', 'pending');
    }

    /** @param Builder<Capture> $query */
    public function scopeAwaitingReview(Builder $query): void
    {
        $query->where('status', 'reviewing')->whereHas('items', fn ($q) => $q->where('status', 'pending'));
    }

    public function markProcessing(): void
    {
        $this->forceFill(['status' => 'processing', 'error' => null])->save();
    }

    public function markFailed(string $message): void
    {
        $this->forceFill([
            'status' => 'failed',
            'error' => mb_substr($message, 0, 1000),
            'processed_at' => now(),
        ])->save();
    }

    /** Nothing found is a finished capture, not a failed one. */
    public function markReviewed(?string $summary, int $itemCount): void
    {
        $this->forceFill([
            'status' => $itemCount > 0 ? 'reviewing' : 'done',
            'summary' => $summary,
            'error' => null,
            'processed_at' => now(),
        ])->save();
    }

    /** Once every item has been dealt with, the capture is finished. */
    public function closeIfSettled(): void
    {
        if ($this->status === 'reviewing' && ! $this->items()->where('status', 'pending')->exists()) {
            $this->forceFill(['status' => 'done'])->save();
        }
    }

    public function label(): string
    {
        return $this->subject ?: match ($this->source) {
            'email' => 'Email from '.($this->sender ?: 'someone'),
            'photo' => 'Photo',
            'pdf' => 'PDF',
            'url' => 'Web page',
            'share' => 'Shared to FamilyHub',
            default => 'Pasted text',
        };
    }
}
