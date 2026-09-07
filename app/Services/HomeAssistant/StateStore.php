<?php

namespace App\Services\HomeAssistant;

/**
 * The set of entity states the listener is holding, and how events change it.
 *
 * Pulled out of the command so the protocol handling can be tested without a
 * socket: "does a state_changed event update the right entity" is the question
 * worth asking, and it has nothing to do with websockets.
 */
class StateStore
{
    /** @var array<string, array<string, mixed>> keyed by entity id */
    protected array $rows = [];

    /** @param list<array<string, mixed>> $rows */
    public function seed(array $rows): void
    {
        $this->rows = [];

        foreach ($rows as $row) {
            if (is_array($row) && filled($row['entity_id'] ?? null)) {
                $this->rows[$row['entity_id']] = $row;
            }
        }
    }

    /**
     * Apply one message from Home Assistant.
     *
     * @param  array<string, mixed>  $message
     * @return bool whether anything actually changed
     */
    public function apply(array $message): bool
    {
        if (($message['type'] ?? null) !== 'event') {
            return false;
        }

        $event = $message['event'] ?? [];

        if (($event['event_type'] ?? null) !== 'state_changed') {
            return false;
        }

        $entityId = $event['data']['entity_id'] ?? null;
        $new = $event['data']['new_state'] ?? null;

        if (! is_string($entityId) || $entityId === '') {
            return false;
        }

        // A null new_state means the entity was removed from HA. Dropping it
        // is what makes the wall say "Not responding" rather than showing the
        // last state it ever had, forever.
        if (! is_array($new)) {
            if (! isset($this->rows[$entityId])) {
                return false;
            }

            unset($this->rows[$entityId]);

            return true;
        }

        $this->rows[$entityId] = $new;

        return true;
    }

    /** @return list<array<string, mixed>> in the shape /api/states returns */
    public function rows(): array
    {
        return array_values($this->rows);
    }

    public function count(): int
    {
        return count($this->rows);
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
