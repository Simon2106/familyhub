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

    /**
     * Which drawing to use, by name.
     *
     * A name rather than a glyph: the kiosk has no emoji font, so a forecast
     * that returned one was a forecast the wall could not show. `<x-icon>`
     * turns this into inline SVG that looks the same on every device.
     */
    public function icon(): string
    {
        return match (true) {
            $this->code === 0 => $this->isDay ? 'sun' : 'moon',
            $this->code <= 2 => $this->isDay ? 'sun-cloud' : 'moon',
            $this->code === 3 => 'cloud',
            $this->code <= 48 => 'fog',
            $this->code <= 57 => 'drizzle',
            $this->code <= 67 => 'rain',
            $this->code <= 77 => 'snow',
            $this->code <= 82 => 'rain',
            $this->code <= 86 => 'snow-showers',
            default => 'storm',
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
