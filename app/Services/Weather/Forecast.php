<?php

namespace App\Services\Weather;

/** Now, and what the rest of today looks like. */
class Forecast
{
    public function __construct(
        public readonly float $temperature,
        public readonly int $code,
        public readonly ?float $high = null,
        public readonly ?float $low = null,
        public readonly ?float $rainChance = null,
        public readonly bool $isDay = true,
    ) {}

    /**
     * WMO weather codes, in the words a household uses.
     *
     * Grouped rather than exhaustive: nobody needs "light freezing drizzle"
     * on a kitchen wall, they need to know whether to take a coat.
     */
    public function description(): string
    {
        return match (true) {
            $this->code === 0 => 'Clear',
            $this->code <= 2 => 'Mostly sunny',
            $this->code === 3 => 'Cloudy',
            $this->code <= 48 => 'Foggy',
            $this->code <= 57 => 'Drizzle',
            $this->code <= 67 => 'Rain',
            $this->code <= 77 => 'Snow',
            $this->code <= 82 => 'Showers',
            $this->code <= 86 => 'Snow showers',
            default => 'Thunderstorms',
        };
    }

    public function icon(): string
    {
        return match (true) {
            $this->code === 0 => $this->isDay ? '☀️' : '🌙',
            $this->code <= 2 => $this->isDay ? '🌤️' : '🌙',
            $this->code === 3 => '☁️',
            $this->code <= 48 => '🌫️',
            $this->code <= 57 => '🌦️',
            $this->code <= 67 => '🌧️',
            $this->code <= 77 => '❄️',
            $this->code <= 82 => '🌦️',
            $this->code <= 86 => '🌨️',
            default => '⛈️',
        };
    }

    /** Worth mentioning only when it is actually likely. */
    public function mentionsRain(): bool
    {
        return $this->rainChance !== null && $this->rainChance >= 30;
    }

    public function round(?float $value): ?string
    {
        return $value === null ? null : (string) (int) round($value);
    }
}
