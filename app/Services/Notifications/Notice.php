<?php

namespace App\Services\Notifications;

/** Something worth telling somebody. */
class Notice
{
    public function __construct(
        public readonly string $trigger,
        /** What it is about, so the same thing is never sent twice. */
        public readonly string $subject,
        public readonly string $title,
        public readonly ?string $body = null,
        public readonly ?string $url = null,
    ) {}
}
