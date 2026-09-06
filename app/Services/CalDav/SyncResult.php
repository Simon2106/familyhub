<?php

namespace App\Services\CalDav;

class SyncResult
{
    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        public int $deleted = 0,
        public int $unchanged = 0,
        public bool $incremental = false,
    ) {}

    public function summary(): string
    {
        return sprintf(
            '%s: %d new, %d updated, %d removed, %d unchanged',
            $this->incremental ? 'incremental' : 'full',
            $this->created,
            $this->updated,
            $this->deleted,
            $this->unchanged,
        );
    }

    public function changed(): int
    {
        return $this->created + $this->updated + $this->deleted;
    }
}
