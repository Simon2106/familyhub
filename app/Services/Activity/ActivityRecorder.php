<?php

namespace App\Services\Activity;

use App\Models\ActivityEvent;
use App\Models\Household;
use App\Services\HomeAssistant\EntityState;
use App\Services\HomeAssistant\HomeAssistant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Noticing what the house did.
 *
 * Two ways in, and they agree. The websocket listener already receives every
 * state change in Home Assistant and can hand them straight here; where it
 * is not running, a scheduled poll compares what it last saw against what HA
 * says now. The poll is the fallback rather than the design: it will miss a
 * door that opened and shut between two reads, and that is an acceptable
 * thing to miss when the alternative is nothing at all.
 *
 * Only the entities somebody chose in /admin. A Zigbee network invents a
 * couple of hundred, and a feed of all of them is a feed nobody reads.
 */
class ActivityRecorder
{
    /** What the poll last saw, so it can tell a change from a repeat. */
    public const SEEN_KEY = 'activity:last-seen';

    public const SEEN_TTL_HOURS = 48;

    /** Whether the warning has been said this run. */
    protected bool $complained = false;

    public function __construct(protected HomeAssistant $ha) {}

    /**
     * Record one change, if it is one the household asked to watch.
     *
     * Returns the row, or null when the entity is not watched, the state did
     * not actually change, or it is the first time we have ever seen it —
     * the first read of an entity is not an event, it is a baseline.
     */
    public function record(
        Household $household,
        string $entityId,
        ?string $from,
        string $to,
        ?CarbonImmutable $at = null,
    ): ?ActivityEvent {
        $watched = $household->activityEntities();

        if (! isset($watched[$entityId])) {
            return null;
        }

        $from = $this->tidy($from);
        $to = $this->tidy($to);

        // Nothing happened, or HA lost touch — neither is news.
        if ($to === $from || $to === '' || in_array($to, ['unavailable', 'unknown'], true)) {
            return null;
        }

        $config = $watched[$entityId];

        return ActivityEvent::create([
            'household_id' => $household->id,
            'entity_id' => $entityId,
            'category' => $config['category'],
            'label' => $config['label'],
            'from_state' => $from === '' ? null : $from,
            'to_state' => $to,
            'at' => $at ?? CarbonImmutable::now(),
        ]);
    }

    /**
     * Compare what HA says now against what was last seen.
     *
     * The polling fallback, run on a schedule. Returns how many rows it
     * wrote, which is what the command prints.
     */
    public function poll(Household $household, ?CarbonImmutable $at = null): int
    {
        $watched = $household->activityEntities();

        if ($watched === [] || ! $this->ha->isConfigured()) {
            return 0;
        }

        try {
            // Fresh, not cached. This is the fallback for when the websocket
            // listener is not running, so there is nothing keeping the cache
            // warm — and a poll that reads a cache it filled itself a moment
            // ago is a poll that never notices anything.
            $states = $this->ha->look(array_keys($watched), fresh: true);
        } catch (Throwable $e) {
            if (! $this->complained) {
                $this->complained = true;

                Log::warning('Could not read the activity entities', ['error' => $e->getMessage()]);
            }

            return 0;
        }

        $seen = $this->lastSeen($household);
        $written = 0;

        foreach ($states as $entityId => $state) {
            /** @var EntityState $state */
            $before = $seen[$entityId] ?? null;

            $seen[$entityId] = $state->state;

            // The very first read is a baseline, not an event: otherwise
            // turning this on would write a row for every watched entity in
            // the house at once.
            if ($before === null) {
                continue;
            }

            if ($this->record($household, $entityId, $before, $state->state, $at)) {
                $written++;
            }
        }

        Cache::put($this->seenKey($household), $seen, now()->addHours(self::SEEN_TTL_HOURS));

        return $written;
    }

    /**
     * Take what the websocket listener saw.
     *
     * The listener holds every entity in HA; this picks out the watched ones
     * and ignores the rest, so the daemon does not need to know what a
     * household has chosen.
     *
     * @param  array<string, mixed>  $event  the data of a state_changed event
     */
    public function fromListener(Household $household, array $event, ?CarbonImmutable $at = null): ?ActivityEvent
    {
        $entityId = $event['entity_id'] ?? null;

        if (! is_string($entityId)) {
            return null;
        }

        $to = $event['new_state']['state'] ?? null;

        if (! is_string($to)) {
            return null;
        }

        $from = $event['old_state']['state'] ?? null;

        return $this->record(
            $household,
            $entityId,
            is_string($from) ? $from : null,
            $to,
            $at,
        );
    }

    protected function tidy(?string $state): string
    {
        return mb_strtolower(trim((string) $state));
    }

    /** @return array<string, string> */
    protected function lastSeen(Household $household): array
    {
        $seen = Cache::get($this->seenKey($household), []);

        return is_array($seen) ? $seen : [];
    }

    protected function seenKey(Household $household): string
    {
        return self::SEEN_KEY.':'.$household->id;
    }
}
