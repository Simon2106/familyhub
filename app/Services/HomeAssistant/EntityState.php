<?php

namespace App\Services\HomeAssistant;

/** One Home Assistant entity as it is right now. */
class EntityState
{
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

    public function domain(): string
    {
        return str_contains($this->entityId, '.') ? explode('.', $this->entityId)[0] : '';
    }

    public function name(): string
    {
        return (string) ($this->attributes['friendly_name'] ?? $this->entityId);
    }

    /** Whether the thing is doing something, for the domains where that means anything. */
    public function isOn(): bool
    {
        return match ($this->domain()) {
            'cover' => in_array($this->state, ['open', 'opening'], true),
            'climate' => $this->state !== 'off' && ! $this->isUnavailable(),
            default => $this->state === 'on',
        };
    }

    /**
     * HA reports unknown and unavailable for things it has lost touch with.
     *
     * Worth showing as its own state: a tile that renders a missing radiator
     * valve as "off" is telling the household something untrue.
     *
     * Scenes and scripts are exempt. A scene holds the time it was last
     * activated, so a scene that has never run this boot reports "unknown" —
     * which means nothing is wrong, only that there is nothing to say.
     */
    public function isUnavailable(): bool
    {
        if ($this->isRunnable()) {
            return false;
        }

        return in_array($this->state, ['unavailable', 'unknown', ''], true);
    }

    /** Something you do rather than something that is on or off. */
    public function isRunnable(): bool
    {
        return in_array($this->domain(), ['scene', 'script'], true);
    }

    public function currentTemperature(): ?float
    {
        $value = $this->attributes['current_temperature'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    public function targetTemperature(): ?float
    {
        $value = $this->attributes['temperature'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /** What a tile says under the name. */
    public function summary(): string
    {
        if ($this->isRunnable()) {
            return 'Tap to run';
        }

        if ($this->isUnavailable()) {
            return 'Not responding';
        }

        return match ($this->domain()) {
            'climate' => $this->climateSummary(),
            'cover' => ucfirst($this->state),
            default => $this->isOn() ? 'On' : 'Off',
        };
    }

    protected function climateSummary(): string
    {
        $parts = array_filter([
            $this->currentTemperature() !== null ? $this->round($this->currentTemperature()).'°' : null,
            $this->targetTemperature() !== null ? '→ '.$this->round($this->targetTemperature()).'°' : null,
        ]);

        return $parts === [] ? ucfirst($this->state) : implode(' ', $parts);
    }

    protected function round(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
