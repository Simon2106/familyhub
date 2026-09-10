<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing somebody has been told, or is waiting to be.
 *
 * The row exists before the sending. That is what makes "never twice" true
 * whatever happens next: a push that fails, a quiet hour that holds it back,
 * a digest that carries it instead — all of them are the same row.
 */
#[Fillable(['user_id', 'trigger', 'subject', 'title', 'body', 'url', 'sent_at', 'digested_at'])]
class NoticeSent extends Model
{
    protected $table = 'notices_sent';

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'digested_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
