<?php

namespace App\Services\HomeAssistant;

use App\Exceptions\HomeAssistantException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * What the app asks Home Assistant to do.
 *
 * Only the six domains the household actually has tiles for. Anything else in
 * HA — sensors, automations, the two hundred entities a Zigbee network invents
 * — is deliberately invisible, because a picker listing all of them is a picker
 * nobody finishes using.
 */
class HomeAssistant
{
    public const DOMAINS = ['light', 'switch', 'climate', 'cover', 'scene', 'script'];

    protected const STATES_KEY = 'ha:states';

    public function __construct(protected Client $client) {}

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * Every state we care about, keyed by entity id.
     *
     * Cached briefly and shared: a wall with a dozen tiles must not be a dozen
     * requests to a Raspberry Pi. The websocket listener writes to this same
     * key, so when it is running these reads never touch HA at all.
     *
     * @return Collection<string, EntityState>
     */
    public function states(bool $fresh = false): Collection
    {
        if ($fresh) {
            Cache::forget(self::STATES_KEY);
        }

        $rows = Cache::remember(
            self::STATES_KEY,
            config('familyhub.homeassistant.cache_seconds'),
            fn () => $this->client->get('/api/states'),
        );

        return collect($rows)
            ->map(fn (array $row) => EntityState::fromArray($row))
            ->filter(fn (EntityState $state) => in_array($state->domain(), self::DOMAINS, true))
            ->keyBy(fn (EntityState $state) => $state->entityId);
    }

    public function state(string $entityId): ?EntityState
    {
        return $this->states()->get($entityId);
    }

    /**
     * Everything pickable, with the room it belongs to.
     *
     * Areas are not in the REST API, so this asks HA to render them. One
     * template call rather than a request per entity, which matters when the
     * answer is a few hundred entities long.
     *
     * @return Collection<int, array{entity_id: string, name: string, domain: string, area: ?string}>
     */
    public function pickable(): Collection
    {
        $json = $this->client->render($this->areaTemplate());
        $rows = json_decode($json, true);

        if (! is_array($rows)) {
            throw new HomeAssistantException(
                'Home Assistant did not return a readable list of entities. '
                .'Check the token has access to the template API.'
            );
        }

        return collect($rows)
            ->filter(fn ($row) => is_array($row) && filled($row['entity_id'] ?? null))
            ->map(fn (array $row) => [
                'entity_id' => (string) $row['entity_id'],
                'name' => (string) ($row['name'] ?? $row['entity_id']),
                'domain' => (string) ($row['domain'] ?? ''),
                'area' => filled($row['area'] ?? null) ? (string) $row['area'] : null,
            ])
            ->sortBy([['area', 'asc'], ['name', 'asc']])
            ->values();
    }

    /** Flip a light, switch or cover; run a scene or script. */
    public function toggle(string $entityId): void
    {
        $domain = $this->domainOf($entityId);

        match ($domain) {
            'light', 'switch' => $this->call($domain, 'toggle', $entityId),
            // A scene or script has no off. Tapping it means "do it".
            'scene', 'script' => $this->call($domain, 'turn_on', $entityId),
            'cover' => $this->call('cover', 'toggle', $entityId),
            'climate' => $this->call('climate', $this->state($entityId)?->isOn() ? 'turn_off' : 'turn_on', $entityId),
            default => throw new HomeAssistantException("Nothing to toggle on {$entityId}."),
        };
    }

    public function setTemperature(string $entityId, float $celsius): void
    {
        // Somewhere between frost protection and dangerous.
        $this->call('climate', 'set_temperature', $entityId, ['temperature' => max(5, min(30, $celsius))]);
    }

    public function cover(string $entityId, string $action): void
    {
        $service = match ($action) {
            'open' => 'open_cover',
            'close' => 'close_cover',
            'stop' => 'stop_cover',
            default => throw new HomeAssistantException("Unknown cover action {$action}."),
        };

        $this->call('cover', $service, $entityId);
    }

    /** @param array<string, mixed> $data */
    public function call(string $domain, string $service, string $entityId, array $data = []): void
    {
        $this->client->post("/api/services/{$domain}/{$service}", $data + ['entity_id' => $entityId]);

        // The next read must not serve the state from before the tap.
        Cache::forget(self::STATES_KEY);
    }

    /**
     * States straight from HA, unfiltered and uncached.
     *
     * The listener seeds from this: it wants every row exactly as the REST
     * API gives them, because those are what it will be patching.
     *
     * @return list<array<string, mixed>>
     */
    public function rawStates(): array
    {
        return $this->client->get('/api/states');
    }

    /** Replace the shared state cache — used by the websocket listener. */
    public function remember(array $rows): void
    {
        Cache::put(self::STATES_KEY, $rows, now()->addMinutes(5));
    }

    public function forget(): void
    {
        Cache::forget(self::STATES_KEY);
    }

    protected function domainOf(string $entityId): string
    {
        return str_contains($entityId, '.') ? explode('.', $entityId)[0] : '';
    }

    /** One template that answers "what is there, and which room is it in". */
    protected function areaTemplate(): string
    {
        $domains = "'".implode("','", self::DOMAINS)."'";

        return <<<JINJA
        {%- set out = namespace(items=[]) -%}
        {%- for s in states -%}
        {%- if s.domain in [{$domains}] -%}
        {%- set out.items = out.items + [{'entity_id': s.entity_id, 'name': s.name, 'domain': s.domain, 'area': area_name(s.entity_id)}] -%}
        {%- endif -%}
        {%- endfor -%}
        {{ out.items | tojson }}
        JINJA;
    }
}
