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

    protected function firstOf(mixed $values): ?float
    {
        return is_array($values) && isset($values[0]) && is_numeric($values[0]) ? (float) $values[0] : null;
    }
}
