<?php

namespace App\Services\HomeAssistant;

use App\Models\Household;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Whether anybody is near the wall.
 *
 * An mmWave sensor exposes one binary_sensor per zone, so "near" and "in the
 * room" are two entities rather than a distance. That is all this needs: the
 * wall wakes for the near zone and holds for a minute and a half after it
 * clears, shows the ordinary screensaver while somebody is merely in the
 * room, and goes to a black screen and a faint clock when the room has been
 * empty a while.
 *
 * Both are debounced, and the two debounces are deliberately lopsided. Two
 * seconds before waking, so walking past on the way to the back door does
 * not light the kitchen up; twenty seconds of clear before it counts as
 * left, so somebody standing still at the worktop does not keep vanishing.
 * An mmWave sensor is confident about movement and much less so about a
 * person who has stopped moving.
 */
class Presence
{
    /** How long the near zone must be occupied before the wall wakes. */
    public const WAKE_AFTER_SECONDS = 2;

    /** How long it must be clear before the wall believes they have gone. */
    public const CLEAR_AFTER_SECONDS = 20;

    /** How long the face is held after the near zone clears. */
    public const HOLD_SECONDS = 90;

    /** How long an empty room waits before the wall goes dark. */
    public const DEEP_SLEEP_MINUTES = 10;

    public const STATE_KEY = 'ha:presence';

    protected bool $complained = false;

    public function __construct(protected HomeAssistant $ha) {}

    public function isConfigured(Household $household): bool
    {
        $zones = $household->presenceZones();

        return filled($zones['near']) || filled($zones['room']);
    }

    /**
     * What the sensors say right now, after debouncing.
     *
     * Everything the browser needs to decide what to show, as plain numbers
     * and booleans — the decision itself is made in the browser, because it
     * has to happen on a clock faster than a poll.
     *
     * @return array{
     *     configured: bool, readable: bool, near: bool, room: bool,
     *     nearSince: ?int, roomSince: ?int, clearFor: ?int,
     *     holdSeconds: int, deepSleepMinutes: int, lux: ?float, simulated: bool
     * }
     */
    public function read(Household $household, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $zones = $household->presenceZones();

        $base = [
            'configured' => $this->isConfigured($household),
            'readable' => false,
            'near' => false,
            'room' => false,
            'nearSince' => null,
            'roomSince' => null,
            'clearFor' => null,
            'holdSeconds' => self::HOLD_SECONDS,
            'deepSleepMinutes' => $household->deepSleepMinutes(),
            'lux' => null,
            'simulated' => false,
        ];

        // The simulate switch in /admin, for a wall whose sensor has not
        // been fitted yet. Deliberately ahead of the real read: it is there
        // so the behaviour can be seen without the hardware.
        if ($pretend = $household->simulatedPresence()) {
            // array_merge, not +: the union operator keeps the left-hand
            // value for a duplicate key, so every pretended zone was being
            // silently thrown away in favour of the all-false base.
            // Configured as well as readable: the wall ignores a presence
            // read it has been told is not set up, and a simulate switch
            // the wall ignores is a simulate switch that tests nothing.
            return array_merge(
                $base,
                ['configured' => true, 'readable' => true, 'simulated' => true],
                $this->pretend($pretend, $now),
            );
        }

        if (! $base['configured'] || ! $this->ha->isConfigured()) {
            return $base;
        }

        try {
            $states = $this->ha->look(array_values(array_filter([
                $zones['near'], $zones['room'], $zones['lux'],
            ])));
        } catch (Throwable $e) {
            if (! $this->complained) {
                $this->complained = true;

                Log::warning('Could not read the presence sensors', ['error' => $e->getMessage()]);
            }

            return $base;
        }

        $near = $this->occupied($states->get((string) $zones['near']));
        $room = $this->occupied($states->get((string) $zones['room']));

        $kept = $this->remember($near, $room, $now);

        $lux = $states->get((string) $zones['lux'])?->state;

        return [
            ...$base,
            'readable' => true,
            'near' => $this->debounced($near, $kept['nearSince'], $kept['nearClearedAt'], $now),
            'room' => $this->debounced($room, $kept['roomSince'], $kept['roomClearedAt'], $now),
            'nearSince' => $kept['nearSince'],
            'roomSince' => $kept['roomSince'],
            'clearFor' => $kept['roomClearedAt'] === null ? null : $now->getTimestamp() - $kept['roomClearedAt'],
            'lux' => is_numeric($lux) ? (float) $lux : null,
        ];
    }

    /**
     * The debounce.
     *
     * Occupied counts once it has been occupied for two seconds; clear
     * counts once it has been clear for twenty. In between, the previous
     * answer stands — which is what stops a wall flapping at somebody
     * walking past it.
     */
    protected function debounced(bool $raw, ?int $since, ?int $clearedAt, CarbonImmutable $now): bool
    {
        if ($raw) {
            return $since !== null && $now->getTimestamp() - $since >= self::WAKE_AFTER_SECONDS;
        }

        // Still counted as occupied until the clear has held.
        return $clearedAt !== null && $now->getTimestamp() - $clearedAt < self::CLEAR_AFTER_SECONDS;
    }

    /**
     * Keep track of when each zone last changed.
     *
     * @return array{nearSince: ?int, roomSince: ?int, nearClearedAt: ?int, roomClearedAt: ?int}
     */
    protected function remember(bool $near, bool $room, CarbonImmutable $now): array
    {
        $kept = Cache::get(self::STATE_KEY, []);
        $kept = is_array($kept) ? $kept : [];

        $at = $now->getTimestamp();

        foreach (['near' => $near, 'room' => $room] as $zone => $occupied) {
            if ($occupied) {
                $kept[$zone.'Since'] ??= $at;
                $kept[$zone.'ClearedAt'] = null;
            } else {
                $kept[$zone.'ClearedAt'] ??= $at;
                $kept[$zone.'Since'] = null;
            }
        }

        Cache::put(self::STATE_KEY, $kept, now()->addHours(6));

        return [
            'nearSince' => $kept['nearSince'] ?? null,
            'roomSince' => $kept['roomSince'] ?? null,
            'nearClearedAt' => $kept['nearClearedAt'] ?? null,
            'roomClearedAt' => $kept['roomClearedAt'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    protected function pretend(string $what, CarbonImmutable $now): array
    {
        $at = $now->getTimestamp() - self::WAKE_AFTER_SECONDS - 1;

        return match ($what) {
            'near' => ['near' => true, 'room' => true, 'nearSince' => $at, 'roomSince' => $at, 'clearFor' => null],
            'room' => ['near' => false, 'room' => true, 'nearSince' => null, 'roomSince' => $at, 'clearFor' => null],
            default => ['near' => false, 'room' => false, 'nearSince' => null, 'roomSince' => null, 'clearFor' => 3600],
        };
    }

    protected function occupied(?EntityState $state): bool
    {
        if ($state === null || $state->isUnavailable()) {
            return false;
        }

        return in_array(mb_strtolower($state->state), ['on', 'home', 'detected', 'occupied', 'true'], true);
    }

    /** What /admin shows for debugging. */
    public function explain(Household $household): array
    {
        $read = $this->read($household);
        $zones = $household->presenceZones();

        return [
            ...$read,
            'entities' => $zones,
            'lastNear' => $read['nearSince'] ? CarbonImmutable::createFromTimestamp($read['nearSince']) : null,
            'lastRoom' => $read['roomSince'] ? CarbonImmutable::createFromTimestamp($read['roomSince']) : null,
            'listening' => $this->listenerIsUp(),
        ];
    }

    /** Whether the websocket daemon has touched the cache recently. */
    public function listenerIsUp(): bool
    {
        return Cache::has('ha:states');
    }
}
