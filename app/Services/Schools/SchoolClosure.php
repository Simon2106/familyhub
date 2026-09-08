<?php

namespace App\Services\Schools;

use Carbon\CarbonImmutable;

/** A stretch of days one school is shut, as the wall wants to show it. */
class SchoolClosure
{
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        /** holiday | inset */
        public readonly string $kind,
        public readonly CarbonImmutable $startsOn,
        public readonly CarbonImmutable $endsOn,
    ) {}

    public function covers(CarbonImmutable $date): bool
    {
        $day = $date->toDateString();

        return $day >= $this->startsOn->toDateString() && $day <= $this->endsOn->toDateString();
    }

    public function isInset(): bool
    {
        return $this->kind === 'inset';
    }

    /** An INSET day earns more attention than the third week of August. */
    public function colour(): string
    {
        return $this->isInset() ? '#c026d3' : '#0d9488';
    }
}
