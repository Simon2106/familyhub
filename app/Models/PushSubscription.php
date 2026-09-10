<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One browser that has agreed to be told things. */
#[Fillable(['user_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth', 'device_label'])]
class PushSubscription extends Model
{
    protected function casts(): array
    {
        return ['last_sent_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Endpoints are long and vary in length; the hash is what is indexed. */
    public static function hash(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
