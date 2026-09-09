<?php

namespace App\Services\HomeAssistant;

use App\Exceptions\HomeAssistantException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

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

    /**
     * This instance's copy of what Home Assistant said.
     *
     * The shared cache is short and may be switched off entirely, so without
     * this a page wanting both the tiles and whatever is playing asks the Pi
     * twice for the same rows. Per instance rather than per process on
     * purpose: one instance is one render, and a longer-lived memo would be a
     * screen showing a light that went off a minute ago.
     *
     * @var list<array<string, mixed>>|null
     */
    protected ?array $rows = null;

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
            $this->forget();
        }

        return collect($this->cachedRows())
            ->map(fn (array $row) => EntityState::fromArray($row))
            ->filter(fn (EntityState $state) => in_array($state->domain(), self::DOMAINS, true))
            ->keyBy(fn (EntityState $state) => $state->entityId);
    }

    public function state(string $entityId): ?EntityState
    {
        return $this->states()->get($entityId);
    }

    /**
     * Every row HA gave us, cached and unfiltered.
     *
     * One cache for the whole app: the tiles want six domains and now-playing
     * wants a seventh, and reading /api/states twice per render to serve two
     * different filters is a request per second at a Raspberry Pi.
     *
     * @return list<array<string, mixed>>
     */
    protected function cachedRows(): array
    {
        return $this->rows ??= Cache::remember(
            self::STATES_KEY,
            config('familyhub.homeassistant.cache_seconds'),
            fn () => $this->client->get('/api/states'),
        );
    }

    /**
     * Whatever is playing in the house.
     *
     * Deliberately not part of DOMAINS: media players are found rather than
     * chosen. Nobody should have to add the kitchen speaker to the wall before
     * the wall will admit music is coming out of it.
     *
     * @return Collection<string, MediaPlayer>
     */
    public function mediaPlayers(): Collection
    {
        return collect($this->cachedRows())
            ->map(fn (array $row) => MediaPlayer::fromArray($row))
            ->filter(fn (MediaPlayer $player) => str_starts_with($player->entityId, 'media_player.'))
            ->keyBy(fn (MediaPlayer $player) => $player->entityId);
    }

    /**
     * When the sun next rises and sets, if Home Assistant is tracking it.
     *
     * Read straight off sun.sun rather than worked out here: HA already knows
     * the house's latitude, and two implementations of dusk would disagree
     * twice a year.
     *
     * @return array{rising: ?CarbonImmutable, setting: ?CarbonImmutable}
     */
    public function sun(): array
    {
        $sun = collect($this->cachedRows())->firstWhere('entity_id', 'sun.sun');
        $attributes = is_array($sun['attributes'] ?? null) ? $sun['attributes'] : [];

        $at = function (?string $value): ?CarbonImmutable {
            try {
                return filled($value) ? CarbonImmutable::parse($value) : null;
            } catch (Throwable) {
                return null;
            }
        };

        return [
            'rising' => $at($attributes['next_rising'] ?? null),
            'setting' => $at($attributes['next_setting'] ?? null),
        ];
    }

    public function tracksTheSun(): bool
    {
        $sun = $this->sun();

        return $sun['rising'] !== null || $sun['setting'] !== null;
    }

    /**
     * Play, pause, skip or change the volume of something already playing.
     *
     * An allow-list rather than a service name passed through: this is reached
     * from a wall anyone can walk up to, and "call any service on any entity"
     * is not a thing a kitchen screen needs to be able to do.
     */
    public function media(string $entityId, string $action): void
    {
        $service = match ($action) {
            'play-pause' => 'media_play_pause',
            'next' => 'media_next_track',
            'previous' => 'media_previous_track',
            'louder' => 'volume_up',
            'quieter' => 'volume_down',
            default => throw new HomeAssistantException("Unknown media action {$action}."),
        };

        if (! $this->mediaPlayers()->has($entityId)) {
            throw new HomeAssistantException("{$entityId} is not a media player.");
        }

        $this->call('media_player', $service, $entityId);
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
        $this->forget();
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
        $this->rows = $rows;

        Cache::put(self::STATES_KEY, $rows, now()->addMinutes(5));
    }

    public function forget(): void
    {
        $this->rows = null;

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
