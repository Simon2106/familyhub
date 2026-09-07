<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;

/**
 * Stands in for a Home Assistant install.
 *
 * State-driven from statics read at request time, because Http::fake() MERGES
 * stubs rather than replacing them — the same trap FakeICloud exists to avoid.
 */
class FakeHomeAssistant
{
    public const URL = 'http://homeassistant.local';

    public const TOKEN = 'long-lived-token';

    /** @var list<array<string, mixed>> */
    public static array $states = [];

    /** @var list<array<string, mixed>> */
    public static array $pickable = [];

    /** @var list<array{path: string, body: mixed}> */
    public static array $calls = [];

    public static ?int $failStatus = null;

    public static function fake(): void
    {
        self::reset();

        config([
            'familyhub.homeassistant.url' => self::URL,
            'familyhub.homeassistant.token' => self::TOKEN,
            'familyhub.homeassistant.cache_seconds' => 0,
        ]);

        Http::fake([
            self::URL.'/api/*' => function ($request) {
                $path = parse_url((string) $request->url(), PHP_URL_PATH);

                self::$calls[] = ['path' => $path, 'body' => $request->data()];

                if (self::$failStatus !== null) {
                    return Http::response(['message' => 'no'], self::$failStatus);
                }

                return match (true) {
                    $path === '/api/states' => Http::response(self::$states),
                    $path === '/api/template' => Http::response(json_encode(self::$pickable), 200, ['Content-Type' => 'text/plain']),
                    str_starts_with($path, '/api/services/') => Http::response([]),
                    default => Http::response([]),
                };
            },
        ]);
    }

    public static function reset(): void
    {
        self::$states = [];
        self::$pickable = [];
        self::$calls = [];
        self::$failStatus = null;
    }

    /** @param array<string, mixed> $attributes */
    public static function entity(string $entityId, string $state, array $attributes = [], ?string $area = null): void
    {
        $name = $attributes['friendly_name'] ?? $entityId;

        self::$states[] = ['entity_id' => $entityId, 'state' => $state, 'attributes' => $attributes];
        self::$pickable[] = [
            'entity_id' => $entityId,
            'name' => $name,
            'domain' => explode('.', $entityId)[0],
            'area' => $area,
        ];
    }

    /** @return list<array{path: string, body: mixed}> */
    public static function serviceCalls(): array
    {
        return array_values(array_filter(
            self::$calls,
            fn (array $call) => str_starts_with($call['path'], '/api/services/'),
        ));
    }
}
