<?php

namespace App\Services\HomeAssistant;

/**
 * Something playing, as Home Assistant sees it.
 *
 * Kept apart from EntityState because a media player is not a thing that is on
 * or off: it is a thing with a title, an artist, artwork and a set of controls
 * that differs per device — a radio has no next track, a speaker group has no
 * artwork, and a television reports a channel where an album would be.
 */
class MediaPlayer
{
    /**
     * The bits of HA's supported_features that matter to a wall.
     *
     * A device advertises what it can do; showing a next-track button on a
     * radio is how a wall teaches the household not to trust its buttons.
     */
    public const PAUSE = 1;

    public const VOLUME_SET = 4;

    public const PREVIOUS = 16;

    public const NEXT = 32;

    public const VOLUME_STEP = 1024;

    public const PLAY = 16384;

    /** @param array<string, mixed> $attributes */
    public function __construct(
        public readonly string $entityId,
        public readonly string $state,
        public readonly array $attributes = [],
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            entityId: (string) ($row['entity_id'] ?? ''),
            state: (string) ($row['state'] ?? 'unknown'),
            attributes: is_array($row['attributes'] ?? null) ? $row['attributes'] : [],
        );
    }

    public function name(): string
    {
        return (string) ($this->attributes['friendly_name'] ?? $this->entityId);
    }

    /** Worth showing at all: something is on it, playing or paused. */
    public function isActive(): bool
    {
        return in_array($this->state, ['playing', 'paused', 'buffering'], true);
    }

    public function isPlaying(): bool
    {
        return in_array($this->state, ['playing', 'buffering'], true);
    }

    /** What is playing. */
    public function title(): ?string
    {
        return $this->text('media_title');
    }

    /**
     * Who it is by — or, failing that, what it is part of.
     *
     * A song has an artist, an episode has a programme, a stream has only the
     * app it is coming from. All three answer the same question from across a
     * kitchen: what is this?
     */
    public function subtitle(): ?string
    {
        foreach (['media_artist', 'media_series_title', 'media_album_name', 'media_channel', 'app_name', 'source'] as $key) {
            if ($value = $this->text($key)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Album art, made absolute.
     *
     * HA gives a path with its own signature already in it, so this needs no
     * token — but it does need the browser to be able to reach Home Assistant,
     * which the wall can and a phone off the house network cannot. The picture
     * is therefore decoration: everything the tile says is said in text too.
     */
    public function artwork(?string $baseUrl = null): ?string
    {
        $picture = $this->text('entity_picture');
        $baseUrl = rtrim((string) ($baseUrl ?? config('familyhub.homeassistant.url')), '/');

        if ($picture === null || $baseUrl === '') {
            return null;
        }

        return str_starts_with($picture, 'http') ? $picture : $baseUrl.'/'.ltrim($picture, '/');
    }

    /** Does the device say it can do this? */
    public function can(int $feature): bool
    {
        $features = $this->attributes['supported_features'] ?? 0;

        return is_numeric($features) && ((int) $features & $feature) === $feature;
    }

    public function canPlayPause(): bool
    {
        return $this->can(self::PAUSE) || $this->can(self::PLAY);
    }

    public function canChangeVolume(): bool
    {
        return $this->can(self::VOLUME_STEP) || $this->can(self::VOLUME_SET);
    }

    /** One line for anywhere too small for two. */
    public function summary(): string
    {
        $parts = array_filter([$this->title(), $this->subtitle()]);

        if ($parts === []) {
            return $this->isPlaying() ? 'Playing' : ucfirst($this->state);
        }

        return implode(' — ', $parts);
    }

    protected function text(string $key): ?string
    {
        $value = $this->attributes[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
