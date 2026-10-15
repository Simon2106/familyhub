<?php

namespace App\Services\Activity;

use App\Models\ActivityEvent;
use App\Models\Household;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the house has been up to, in sentences.
 *
 * Events are collapsed into clusters: everything within five minutes of each
 * other is one thing that happened, because a door opening, a light coming
 * on and the heating going up at ten past six is one person getting home,
 * not three events.
 *
 * A person starting a cluster leads it — "Jenna home · back door opened ·
 * heating to 20°" — which is how anybody would tell you about it.
 */
class ActivityFeed
{
    public function __construct(protected ActivityPhrase $phrase) {}

    /**
     * The clusters, newest first.
     *
     * @return Collection<int, array{at: CarbonImmutable, parts: list<string>, sentence: string, person: ?string}>
     */
    public function clusters(Household $household, ?CarbonImmutable $since = null, ?CarbonImmutable $now = null): Collection
    {
        $now ??= CarbonImmutable::now();
        $since ??= $now->subDay();

        $events = ActivityEvent::query()
            ->forHousehold($household)
            ->since($since)
            ->orderByDesc('at')
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        return $this->cluster($this->withoutQuieted($household, $events));
    }

    /** The newest cluster, for the strip. */
    public function latest(Household $household, ?CarbonImmutable $now = null): ?array
    {
        return $this->clusters($household, null, $now)->first();
    }

    /**
     * Lights and sockets during quiet hours are noise.
     *
     * Somebody coming home at two in the morning is not, which is why this
     * drops a category rather than a window.
     *
     * @param  Collection<int, ActivityEvent>  $events
     * @return Collection<int, ActivityEvent>
     */
    protected function withoutQuieted(Household $household, Collection $events): Collection
    {
        $quiet = $household->activityQuietHours();

        if ($quiet === null) {
            return $events;
        }

        $tz = $household->displayTimezone();

        return $events->reject(
            fn (ActivityEvent $event) => $event->isQuietable()
                && $this->within($event->at->timezone($tz)->format('H:i'), $quiet['start'], $quiet['end'])
        )->values();
    }

    /** Handles a window that crosses midnight, which most quiet hours do. */
    protected function within(string $at, string $start, string $end): bool
    {
        if ($start === $end) {
            return false;
        }

        return $start < $end
            ? ($at >= $start && $at < $end)
            : ($at >= $start || $at < $end);
    }

    /**
     * Group a run of events into things that happened.
     *
     * @param  Collection<int, ActivityEvent>  $events  newest first
     * @return Collection<int, array{at: CarbonImmutable, parts: list<string>, sentence: string, person: ?string}>
     */
    protected function cluster(Collection $events): Collection
    {
        $clusters = collect();
        /** @var list<ActivityEvent> $current */
        $current = [];

        foreach ($events as $event) {
            if ($current === []) {
                $current[] = $event;

                continue;
            }

            $newest = $current[0]->at;

            if ($newest->diffInMinutes($event->at, true) <= ActivityEvent::WINDOW_MINUTES) {
                $current[] = $event;

                continue;
            }

            $clusters->push($this->describe($current));
            $current = [$event];
        }

        if ($current !== []) {
            $clusters->push($this->describe($current));
        }

        return $clusters;
    }

    /**
     * One cluster, as a sentence.
     *
     * @param  list<ActivityEvent>  $events  newest first within the window
     * @return array{at: CarbonImmutable, parts: list<string>, sentence: string, person: ?string}
     */
    protected function describe(array $events): array
    {
        // Oldest first within the cluster: a person arriving caused the door
        // and the light, and a sentence that puts the cause last reads
        // backwards.
        $ordered = array_reverse($events);

        $people = array_values(array_filter($ordered, fn (ActivityEvent $e) => $e->category === 'people'));

        // A person leads it, wherever in the five minutes they turned up.
        $lead = $people[0] ?? null;

        if ($lead !== null) {
            $ordered = array_merge([$lead], array_values(array_filter(
                $ordered,
                fn (ActivityEvent $e) => $e->id !== $lead->id,
            )));
        }

        $parts = [];

        foreach ($ordered as $event) {
            $part = $this->phrase->for($event);

            // The same thing twice in five minutes is one thing: a door that
            // opened, closed and opened again is still somebody coming in.
            if (! in_array($part, $parts, true)) {
                $parts[] = $part;
            }
        }

        return [
            'at' => $events[0]->at,
            'parts' => $parts,
            'sentence' => implode(' · ', $parts),
            'person' => $lead?->label,
        ];
    }

    /**
     * The last day, grouped by the hour it happened in.
     *
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    public function byHour(Household $household, ?CarbonImmutable $now = null): Collection
    {
        $now ??= CarbonImmutable::now();
        $tz = $household->displayTimezone();

        return $this->clusters($household, $now->subDay(), $now)
            ->groupBy(fn (array $cluster) => $cluster['at']->timezone($tz)->format('H:00'));
    }

    /** Forget what the house did a month ago. */
    public function prune(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        return ActivityEvent::query()
            ->where('at', '<', $now->subDays(ActivityEvent::KEEP_DAYS))
            ->delete();
    }
}
