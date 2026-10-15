<?php

namespace App\Services\HomeAssistant;

use App\Models\Household;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Who is actually in the house.
 *
 * From the person or device_tracker entity each member is mapped to in
 * /admin. Nothing is inferred from a name: a household that has not set the
 * mapping up gets a wall that behaves exactly as it did before, which is the
 * right answer for a feature nobody has configured.
 *
 * The three minutes of grace on a departure is the whole trick. A phone that
 * drops Wi-Fi for forty seconds in the back bedroom reports not_home, and a
 * display that reshuffled its columns every time that happened would be a
 * display somebody turns off. Arrivals are immediate — somebody walking in
 * is the event the wall exists to notice.
 */
class WhosHome
{
    /** How long somebody stays "home" after their tracker says otherwise. */
    public const GRACE_MINUTES = 3;

    /** Where the last-seen-home times live between requests. */
    public const SEEN_KEY = 'ha:whos-home:seen';

    public const SEEN_TTL_HOURS = 48;

    /** Whether the warning about an unreadable HA has been said this request. */
    protected bool $complained = false;

    public function __construct(protected HomeAssistant $ha) {}

    /**
     * Everybody, with whether they are in and where they are if not.
     *
     * Always returns every member, mapped or not: a member with no tracker
     * is "home" as far as the wall is concerned, because the alternative is
     * a household that set up one phone and watched everybody else vanish
     * into the away row.
     *
     * @return Collection<int, array{member: Member, home: bool, mapped: bool, zone: ?string}>
     */
    public function all(Household $household, ?CarbonImmutable $now = null): Collection
    {
        $now ??= CarbonImmutable::now();
        $map = $household->presenceEntities();

        $states = $this->states(array_values($map));
        $seen = $this->lastSeen();
        $changed = false;

        // $seen by reference as well as $changed: captured by value, the
        // arrivals recorded inside this closure never reached the cache, and
        // the departure grace had nothing to measure from — so somebody who
        // lost Wi-Fi for ten seconds left the house immediately.
        $people = $household->members()->get()->map(function (Member $member) use ($map, $states, &$seen, $now, &$changed) {
            $entityId = $map[$member->id] ?? null;

            if (! filled($entityId)) {
                return ['member' => $member, 'home' => true, 'mapped' => false, 'zone' => null];
            }

            $state = $states?->get($entityId);

            // Unreadable is not the same as away. A Pi that is off, or an
            // integration mid-restart, must not empty the house.
            if ($state === null || $state->isUnavailable()) {
                return ['member' => $member, 'home' => true, 'mapped' => true, 'zone' => null];
            }

            $at = $state->state;
            $home = $at === 'home';

            if ($home) {
                $seen[$entityId] = $now->getTimestamp();
                $changed = true;
            } else {
                // Still counted as in until the grace runs out.
                $lastHome = $seen[$entityId] ?? null;

                if ($lastHome !== null && $now->getTimestamp() - $lastHome < self::GRACE_MINUTES * 60) {
                    $home = true;
                }
            }

            return [
                'member' => $member,
                'home' => $home,
                'mapped' => true,
                // "school", "work" — whatever HA calls the zone they are in.
                'zone' => $home || in_array($at, ['not_home', 'unknown', ''], true) ? null : $at,
            ];
        });

        if ($changed) {
            Cache::put(self::SEEN_KEY, $seen, now()->addHours(self::SEEN_TTL_HOURS));
        }

        return $people->values();
    }

    /**
     * The members who are in, in the order the household lists them.
     *
     * @return Collection<int, Member>
     */
    public function home(Household $household, ?CarbonImmutable $now = null): Collection
    {
        return $this->all($household, $now)
            ->filter(fn (array $row) => $row['home'])
            ->map(fn (array $row) => $row['member'])
            ->values();
    }

    /** @return Collection<int, array{member: Member, zone: ?string}> */
    public function away(Household $household, ?CarbonImmutable $now = null): Collection
    {
        return $this->all($household, $now)
            ->reject(fn (array $row) => $row['home'])
            ->map(fn (array $row) => ['member' => $row['member'], 'zone' => $row['zone']])
            ->values();
    }

    /**
     * Members in the order the wall should show them: at home first.
     *
     * Stable within each group, so a column does not jump about between the
     * two people who are both in.
     *
     * @return Collection<int, Member>
     */
    public function ordered(Household $household, ?CarbonImmutable $now = null): Collection
    {
        $rows = $this->all($household, $now);

        return $rows->filter(fn (array $r) => $r['home'])->concat($rows->reject(fn (array $r) => $r['home']))
            ->map(fn (array $row) => $row['member'])
            ->values();
    }

    /**
     * The one case worth changing the whole face for.
     *
     * A child in on their own wants their own routine and their own jobs,
     * and has no use at all for the shopping list or which bin goes out.
     */
    public function loneChild(Household $household, ?CarbonImmutable $now = null): ?Member
    {
        $home = $this->home($household, $now);

        if ($home->count() !== 1) {
            return null;
        }

        $only = $home->first();

        return $only?->is_child ? $only : null;
    }

    /** Whether anybody has set this up at all. */
    public function isConfigured(Household $household): bool
    {
        return $household->presenceEntities() !== [];
    }

    /** What /admin shows: the computed set, and where it came from. */
    public function explain(Household $household): array
    {
        $map = $household->presenceEntities();
        $states = $this->states(array_values($map));

        return [
            'configured' => $map !== [],
            'readable' => $states !== null,
            'rows' => $this->all($household)->map(fn (array $row) => [
                'name' => $row['member']->name,
                'home' => $row['home'],
                'mapped' => $row['mapped'],
                'zone' => $row['zone'],
                'entity' => $map[$row['member']->id] ?? null,
                'raw' => $states?->get($map[$row['member']->id] ?? '')?->state,
            ])->all(),
        ];
    }

    /**
     * The states, or null when HA cannot be read at all.
     *
     * Null rather than empty, because the two mean opposite things: empty is
     * "nobody is home", null is "we do not know", and only one of those
     * should move anybody into the away row.
     *
     * @param  list<string>  $entityIds
     * @return Collection<string, EntityState>|null
     */
    protected function states(array $entityIds): ?Collection
    {
        $entityIds = array_values(array_filter($entityIds));

        if ($entityIds === [] || ! $this->ha->isConfigured()) {
            return null;
        }

        try {
            return $this->ha->look($entityIds);
        } catch (Throwable $e) {
            // Once per request, not once per member: a kitchen wall polling
            // every minute would otherwise fill a log with the same line.
            if (! $this->complained) {
                $this->complained = true;

                Log::warning('Could not read who is home from Home Assistant', ['error' => $e->getMessage()]);
            }

            return null;
        }
    }

    /** @return array<string, int> entity id to the last time it said home */
    protected function lastSeen(): array
    {
        $seen = Cache::get(self::SEEN_KEY, []);

        return is_array($seen) ? $seen : [];
    }
}
