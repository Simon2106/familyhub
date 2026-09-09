<?php

namespace App\Services\HomeAssistant;

use App\Events\HomeStateChanged;
use App\Jobs\SwitchGroupJob;
use App\Models\Household;
use App\Models\SwitchGroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What a group of switches is doing, and what happens when somebody taps it.
 *
 * Every call here reaches Home Assistant one entity at a time with its own
 * turn_on or turn_off. No HA group, no HA scene: the grouping is this
 * household's idea and belongs where the household can change it.
 *
 * The delayed half is the interesting part. A countdown drawn in the browser
 * is a countdown that dies when the wall sleeps, so the deadline lives on the
 * group row and the switching is done by a queued job. The tile only draws
 * what the server already believes.
 */
class SwitchBoard
{
    public function __construct(protected HomeAssistant $home) {}

    /** @return Collection<int, SwitchGroup> */
    public function groups(?Household $household = null): Collection
    {
        return SwitchGroup::query()
            ->where('household_id', ($household ?? Household::current())->id)
            ->with('entities')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * on | off | mixed | unknown.
     *
     * Mixed is its own answer rather than rounded to one or the other: a tile
     * that says "on" when one of three lamps is lit tells the household
     * something untrue, and the whole point of the tile is to be glanced at.
     *
     * @param  Collection<string, EntityState>  $states
     */
    public function stateOf(SwitchGroup $group, Collection $states): string
    {
        $known = $group->entities
            ->map(fn ($member) => $states->get($member->entity_id))
            ->filter(fn (?EntityState $state) => $state !== null && ! $state->isUnavailable());

        if ($known->isEmpty()) {
            return 'unknown';
        }

        $on = $known->filter(fn (EntityState $state) => $state->isOn())->count();

        return match (true) {
            $on === 0 => 'off',
            $on === $known->count() => 'on',
            default => 'mixed',
        };
    }

    /**
     * A tap.
     *
     * Mixed counts as on, so the tap turns everything off — which is what
     * somebody reaching for a tile that says "2 of 3 on" means by it.
     *
     * @param  Collection<string, EntityState>  $states
     */
    public function press(SwitchGroup $group, Collection $states): string
    {
        // A tap while a countdown is running cancels it. The way out of a
        // mis-tap is the same tap, as everywhere else on the wall.
        if ($group->hasPending()) {
            $this->cancel($group);

            return 'cancelled';
        }

        $direction = $this->stateOf($group, $states) === 'off' ? 'on' : 'off';

        if ($group->isInstant($direction)) {
            $this->apply($group, $direction);

            return $direction;
        }

        $this->schedule($group, $direction, $group->delayFor($direction));

        return 'pending';
    }

    /** Start a countdown, replacing any that was already running. */
    public function schedule(SwitchGroup $group, string $direction, int $minutes): void
    {
        $firesAt = CarbonImmutable::now()->addMinutes(max(1, $minutes));

        $group->forceFill([
            'pending_direction' => $direction,
            'pending_fires_at' => $firesAt,
        ])->save();

        // The deadline goes with the job, so a job left over from a cancelled
        // countdown can tell it is stale and do nothing.
        SwitchGroupJob::dispatch($group->id, $direction, $firesAt->toIso8601String())
            ->delay($firesAt);

        HomeStateChanged::dispatch();
    }

    public function cancel(SwitchGroup $group): void
    {
        $group->forceFill(['pending_direction' => null, 'pending_fires_at' => null])->save();

        HomeStateChanged::dispatch();
    }

    /** Switch every member, one entity at a time, and say so on the wall. */
    public function apply(SwitchGroup $group, string $direction): void
    {
        $service = $direction === 'on' ? 'turn_on' : 'turn_off';

        foreach ($group->entities as $member) {
            $domain = $member->domain();

            if ($domain === '') {
                continue;
            }

            // One failure must not take the rest of the group with it: half a
            // room lit is better than a tap that appeared to do nothing.
            try {
                $this->home->call($domain, $service, $member->entity_id);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $group->forceFill(['pending_direction' => null, 'pending_fires_at' => null])->save();

        HomeStateChanged::dispatch();
    }

    /** One member of a group, toggled on its own — the long-press on the wall. */
    public function toggleMember(SwitchGroup $group, string $entityId): void
    {
        if (! in_array($entityId, $group->entityIds(), true)) {
            return;
        }

        $this->home->toggle($entityId);

        HomeStateChanged::dispatch();
    }
}
