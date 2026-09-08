<?php

namespace App\Services\Schools;

use Carbon\CarbonImmutable;

/** A stretch of days one school is shut, as the wall wants to show it. */
class SchoolClosure
{
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        /** holiday | inset | bank */
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

    public function isBankHoliday(): bool
    {
        return $this->kind === 'bank';
    }

    /**
     * An INSET day earns more attention than the third week of August, and a
     * bank holiday in the middle of term is its own kind of surprise.
     */
    public function colour(): string
    {
        return match ($this->kind) {
            'inset' => '#c026d3',
            'bank' => '#dc2626',
            default => '#0d9488',
        };
    }

    /** Bank holidays close every school, so naming one would be misleading. */
    public function badge(): string
    {
        return $this->isBankHoliday() ? $this->label : trim($this->code.' '.$this->label);
    }
}
