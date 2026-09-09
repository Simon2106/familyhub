<?php

namespace App\Services\HomeAssistant;

use App\Models\Household;
use App\Models\SwitchGroup;
use Carbon\CarbonImmutable;

/**
 * Groups that turn themselves on and off.
 *
 * Checked once a minute rather than scheduled as jobs at boot: a schedule
 * somebody edits at teatime has to take effect this evening, and a queue full
 * of jobs dispatched days ago is a queue nobody can correct.
 *
 * Firing is recorded as a date per direction, so a minute that runs twice does
 * not turn the lamps on twice, and a minute that is missed entirely — a
 * reboot, a slow queue — still fires as soon as the next check comes round.
 */
class SwitchSchedules
{
    /**
     * How late a missed schedule may still fire.
     *
     * Long enough to survive a reboot or a stalled worker, short enough that
     * a wall switched on at midnight does not immediately act out the whole
     * evening it slept through.
     */
    public const GRACE_MINUTES = 30;

    public function __construct(protected SwitchBoard $board, protected HomeAssistant $home) {}

    /** @return list<string> what fired, for the command to report */
    public function run(?Household $household = null, ?CarbonImmutable $now = null): array
    {
        $household ??= Household::current();
        $now ??= $household->nowLocal();

        $sun = $this->home->isConfigured() ? $this->home->sun() : ['rising' => null, 'setting' => null];
        $fired = [];

        foreach ($this->board->groups($household) as $group) {
            foreach (['on', 'off'] as $direction) {
                if ($this->due($group, $direction, $now, $sun, $household)) {
                    $this->fire($group, $direction, $now);
                    $fired[] = $group->name.' '.$direction;
                }
            }
        }

        return $fired;
    }

    /** @param array{rising: ?CarbonImmutable, setting: ?CarbonImmutable} $sun */
    protected function due(
        SwitchGroup $group,
        string $direction,
        CarbonImmutable $now,
        array $sun,
        Household $household,
    ): bool {
        if (! $group->scheduled($direction) || ! $group->runsOn($now)) {
            return false;
        }

        $firedOn = $direction === 'on' ? $group->on_fired_on : $group->off_fired_on;

        if ($firedOn?->isSameDay($now)) {
            return false;
        }

        $at = $this->momentFor($group, $direction, $now, $sun, $household);

        if ($at === null) {
            return false;
        }

        // Due, and not so long ago that acting on it would be a surprise.
        return $now->greaterThanOrEqualTo($at)
            && $now->lessThan($at->addMinutes(self::GRACE_MINUTES));
    }

    /** @param array{rising: ?CarbonImmutable, setting: ?CarbonImmutable} $sun */
    protected function momentFor(
        SwitchGroup $group,
        string $direction,
        CarbonImmutable $now,
        array $sun,
        Household $household,
    ): ?CarbonImmutable {
        $trigger = $direction === 'on' ? $group->on_trigger : $group->off_trigger;
        $time = $direction === 'on' ? $group->on_time : $group->off_time;

        return match ($trigger) {
            'time' => filled($time)
                ? $now->setTimeFromTimeString((string) $time)
                : null,
            // Today's, not the next one: after dusk sun.sun already points at
            // tomorrow, and comparing against that would never be due.
            'sunrise' => $this->today($sun['rising'], $now, $household),
            'sunset' => $this->today($sun['setting'], $now, $household),
            default => null,
        };
    }

    /** The sun event as it falls today, in the household's own clock. */
    protected function today(?CarbonImmutable $moment, CarbonImmutable $now, Household $household): ?CarbonImmutable
    {
        if ($moment === null) {
            return null;
        }

        $local = $moment->setTimezone($household->displayTimezone());

        return $now->setTime((int) $local->format('G'), (int) $local->format('i'));
    }

    /** Honour the group's own instant-or-delayed behaviour. */
    protected function fire(SwitchGroup $group, string $direction, CarbonImmutable $now): void
    {
        $group->forceFill([
            $direction === 'on' ? 'on_fired_on' : 'off_fired_on' => $now->toDateString(),
        ])->save();

        $group->isInstant($direction)
            ? $this->board->apply($group, $direction)
            : $this->board->schedule($group, $direction, $group->delayFor($direction));
    }
}
