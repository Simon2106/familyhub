<?php

namespace App\Services\Weather;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The weather, from Open-Meteo.
 *
 * Chosen because it needs no key: one less credential to keep alive for a
 * screen that has to work untouched for months. Cached hard — the forecast
 * does not change every thirty seconds and a wall that polls it as though it
 * does is rude to a free service.
 */
class OpenMeteo
{
    public const ENDPOINT = 'https://api.open-meteo.com/v1/forecast';

    protected const KEY = 'weather:forecast';

    public function isConfigured(): bool
    {
        return filled(config('familyhub.weather.latitude'))
            && filled(config('familyhub.weather.longitude'));
    }

    /** Null rather than an exception: no weather is a missing tile, not an error page. */
    public function current(): ?Forecast
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $data = Cache::remember(
            self::KEY,
            now()->addMinutes((int) config('familyhub.weather.cache_minutes')),
            fn () => $this->fetch(),
        );

        return $data ? $this->toForecast($data) : null;
    }

    public function forget(): void
    {
        Cache::forget(self::KEY);
    }

    /** @return array<string, mixed>|null */
    protected function fetch(): ?array
    {
        try {
            $response = Http::timeout(10)->get(self::ENDPOINT, [
                'latitude' => config('familyhub.weather.latitude'),
                'longitude' => config('familyhub.weather.longitude'),
                'current' => 'temperature_2m,weather_code,is_day',
                'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_probability_max',
                // The morning face wants to know what it will be doing at
                // the school gate, which is the whole reason anybody looks
                // at a forecast before eight o'clock.
                'hourly' => 'temperature_2m,weather_code,precipitation_probability',
                'timezone' => config('familyhub.timezone'),
                'forecast_days' => 1,
            ]);

            if ($response->failed()) {
                throw new \RuntimeException("Open-Meteo answered {$response->status()}.");
            }

            return $response->json();
        } catch (Throwable $e) {
            // The wall keeps whatever it last knew rather than going blank.
            Log::warning('Could not fetch the weather', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** @param array<string, mixed> $data */
    protected function toForecast(array $data): ?Forecast
    {
        $current = $data['current'] ?? null;

        if (! is_array($current) || ! isset($current['temperature_2m'])) {
            return null;
        }

        $daily = $data['daily'] ?? [];

        return new Forecast(
            temperature: (float) $current['temperature_2m'],
            code: (int) ($current['weather_code'] ?? 0),
            high: $this->firstOf($daily['temperature_2m_max'] ?? null),
            low: $this->firstOf($daily['temperature_2m_min'] ?? null),
            rainChance: $this->firstOf($daily['precipitation_probability_max'] ?? null),
            isDay: (int) ($current['is_day'] ?? 1) === 1,
        );
    }

    /**
     * What it will be doing at a particular hour today.
     *
     * Null when the forecast does not reach that far, or when there is no
     * weather at all — a missing second tile is not worth an error.
     */
    public function atHour(int $hour): ?Forecast
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $data = Cache::remember(
            self::KEY,
            now()->addMinutes((int) config('familyhub.weather.cache_minutes')),
            fn () => $this->fetch(),
        );

        $hourly = is_array($data) ? ($data['hourly'] ?? []) : [];
        $times = $hourly['time'] ?? null;

        if (! is_array($times)) {
            return null;
        }

        // The times come back in the household's own zone, which is what was
        // asked for, so matching on the hour is matching on the hour people
        // mean.
        $wanted = now(config('familyhub.timezone'))->setTime($hour, 0)->format('Y-m-d\\TH:00');
        $at = array_search($wanted, $times, true);

        if ($at === false || ! isset($hourly['temperature_2m'][$at])) {
            return null;
        }

        return new Forecast(
            temperature: (float) $hourly['temperature_2m'][$at],
            code: (int) ($hourly['weather_code'][$at] ?? 0),
            rainChance: isset($hourly['precipitation_probability'][$at])
                ? (float) $hourly['precipitation_probability'][$at]
                : null,
            isDay: $hour >= 7 && $hour < 19,
        );
    }

    protected function firstOf(mixed $values): ?float
    {
        return is_array($values) && isset($values[0]) && is_numeric($values[0]) ? (float) $values[0] : null;
    }
}
