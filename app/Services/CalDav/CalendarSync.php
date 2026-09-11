<?php

namespace App\Services\CalDav;

use App\Exceptions\CalDavException;
use App\Models\Calendar;
use App\Models\Event;
use App\Services\Attribution\AttributionMatcher;
use App\Services\Attribution\EventAttributor;
use App\Services\Calendar\OccurrenceStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pulls one calendar's events down from CalDAV.
 *
 * Prefers sync-collection (RFC 6578), which returns only what changed since the
 * stored token. Falls back to a time-ranged calendar-query where the server
 * does not support it, or the first time a calendar is seen, or when the server
 * rejects a stale token.
 */
class CalendarSync
{
    public function __construct(
        protected CalDavClient $client,
        protected EventMapper $mapper,
        protected EventAttributor $attributor,
        protected OccurrenceStore $occurrences,
    ) {}

    /**
     * Built once per sync and reused for every event, so a run costs one pass
     * over the household's members and places rather than one per event.
     */
    protected ?AttributionMatcher $matcher = null;

    protected function matcher(Calendar $calendar): AttributionMatcher
    {
        return $this->matcher ??= $this->attributor->matcherFor($calendar->account->household);
    }

    public function sync(Calendar $calendar, bool $force = false): SyncResult
    {
        if ($force) {
            // A forced resync must not trust the token it may be diverging from.
            $calendar->forceFill(['sync_token' => null])->save();
        }

        if ($calendar->supports_sync_collection && filled($calendar->sync_token)) {
            try {
                return $this->incremental($calendar);
            } catch (CalDavException $e) {
                // A token the server no longer recognises comes back as 403/409.
                // Falling back to a full pass is the prescribed recovery.
                report($e);

                $calendar->forceFill(['sync_token' => null])->save();
            }
        }

        return $this->full($calendar);
    }

    protected function incremental(Calendar $calendar): SyncResult
    {
        $response = $this->client->report($calendar->external_id, sprintf(
            '<d:sync-collection %s>
                <d:sync-token>%s</d:sync-token>
                <d:sync-level>1</d:sync-level>
                <d:prop><d:getetag/><c:calendar-data/></d:prop>
            </d:sync-collection>',
            $this->client->namespaces(),
            htmlspecialchars($calendar->sync_token, ENT_XML1),
        ));

        $result = new SyncResult(incremental: true);

        $deletedHrefs = $response->resources->filter(fn (DavResource $r) => $r->isDeleted());
        $changed = $response->resources->reject(fn (DavResource $r) => $r->isDeleted());

        $result->deleted = $this->deleteByHref($calendar, $deletedHrefs->pluck('href'));

        $this->applyResources($calendar, $this->withCalendarData($calendar, $changed), $result);

        $calendar->forceFill([
            'sync_token' => $response->syncToken ?: $calendar->sync_token,
            'last_synced_at' => now(),
        ])->save();

        return $result;
    }

    protected function full(Calendar $calendar): SyncResult
    {
        [$from, $to] = $this->window();

        $response = $this->client->report($calendar->external_id, sprintf(
            '<c:calendar-query %s>
                <d:prop><d:getetag/><c:calendar-data/></d:prop>
                <c:filter>
                    <c:comp-filter name="VCALENDAR">
                        <c:comp-filter name="VEVENT">
                            <c:time-range start="%s" end="%s"/>
                        </c:comp-filter>
                    </c:comp-filter>
                </c:filter>
            </c:calendar-query>',
            $this->client->namespaces(),
            $from->format('Ymd\THis\Z'),
            $to->format('Ymd\THis\Z'),
        ));

        $result = new SyncResult(incremental: false);

        $resources = $response->resources->reject(fn (DavResource $r) => $r->isDeleted());

        $seenHrefs = $this->applyResources($calendar, $resources, $result);

        // Anything inside the queried window that the server did not mention is
        // gone. Scoping this to the window matters: events outside it were never
        // asked for, and deleting them would be data loss, not a sync.
        $result->deleted += $this->deleteMissingWithinWindow($calendar, $seenHrefs, $from, $to);

        $calendar->forceFill([
            'sync_token' => $response->syncToken,
            'last_synced_at' => now(),
        ])->save();

        return $result;
    }

    /**
     * sync-collection is allowed to return only etags. Fetch bodies for anything
     * that came back without calendar-data, in one multiget.
     *
     * @param  Collection<int, DavResource>  $resources
     * @return Collection<int, DavResource>
     */
    protected function withCalendarData(Calendar $calendar, Collection $resources): Collection
    {
        $missing = $resources->filter(fn (DavResource $r) => $r->calendarData() === null);

        if ($missing->isEmpty()) {
            return $resources;
        }

        $hrefs = $missing->pluck('href')
            ->map(fn (string $h) => '<d:href>'.htmlspecialchars($h, ENT_XML1).'</d:href>')
            ->implode('');

        $fetched = $this->client->report($calendar->external_id, sprintf(
            '<c:calendar-multiget %s><d:prop><d:getetag/><c:calendar-data/></d:prop>%s</c:calendar-multiget>',
            $this->client->namespaces(),
            $hrefs,
        ));

        $byHref = $fetched->resources->keyBy('href');

        return $resources->map(fn (DavResource $r) => $r->calendarData() === null
            ? ($byHref->get($r->href) ?? $r)
            : $r);
    }

    /**
     * @param  Collection<int, DavResource>  $resources
     * @return Collection<int, string> the hrefs actually applied
     */
    protected function applyResources(Calendar $calendar, Collection $resources, SyncResult $result): Collection
    {
        $applied = collect();

        foreach ($resources as $resource) {
            $ics = $resource->calendarData();

            if ($ics === null) {
                continue;
            }

            $applied->push($resource->href);

            DB::transaction(function () use ($calendar, $resource, $ics, $result) {
                Event::withoutOccurrenceRebuild(function () use ($calendar, $resource, $ics, $result) {
                    $seenUids = [];

                    foreach ($this->mapper->fromIcs($ics) as $attributes) {
                        $uidHash = Event::uidHash($attributes['external_id'], $attributes['recurrence_id']);
                        $seenUids[] = $uidHash;

                        $event = Event::firstOrNew([
                            'calendar_id' => $calendar->id,
                            'uid_hash' => $uidHash,
                        ]);

                        $existed = $event->exists;

                        // An unchanged payload is the common case on a full resync;
                        // skip the write so updated_at stays meaningful.
                        if ($existed && $event->source_hash === $attributes['source_hash'] && $event->etag === $resource->etag()) {
                            $result->unchanged++;

                            continue;
                        }

                        $event->fill($attributes);
                        $event->calendar_id = $calendar->id;
                        $event->href = $resource->href;
                        $event->etag = $resource->etag();
                        $event->needs_push = false;
                        $event->save();

                        // Work out who this event is about while it is in hand.
                        // Already-loaded calendar, so no extra query per event.
                        $event->setRelation('calendar', $calendar);
                        $this->attributor->apply($event, $this->matcher($calendar));

                        $existed ? $result->updated++ : $result->created++;
                    }

                    // One .ics holds a whole recurrence set. An occurrence override
                    // that has been reverted disappears from the file, so anything
                    // at this href we no longer see has gone.
                    $gone = Event::where('calendar_id', $calendar->id)
                        ->where('href', $resource->href)
                        ->whereNotIn('uid_hash', $seenUids)
                        ->get();

                    $gone->each->delete();

                    $removed = $gone->count();

                    $result->deleted += $removed;

                });

                // One .ics is one series. Expanding it here, while the whole
                // set is in hand, is the only moment everything needed —
                // master, overrides and EXDATEs — is known at once.
                $this->occurrences->rebuildForResource($calendar, $resource->href);
            });
        }

        return $applied;
    }

    /** @param Collection<int, string> $hrefs */
    protected function deleteByHref(Calendar $calendar, Collection $hrefs): int
    {
        if ($hrefs->isEmpty()) {
            return 0;
        }

        $events = Event::where('calendar_id', $calendar->id)
            ->whereIn('href', $hrefs->all())
            ->get();

        // Deleted one by one rather than in bulk so the model hook that
        // clears occurrences actually fires.
        $events->each->delete();

        return $events->count();
    }

    /** @param Collection<int, string> $seenHrefs */
    protected function deleteMissingWithinWindow(
        Calendar $calendar,
        Collection $seenHrefs,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): int {
        return Event::where('calendar_id', $calendar->id)
            ->whereNotNull('href')
            ->when($seenHrefs->isNotEmpty(), fn ($q) => $q->whereNotIn('href', $seenHrefs->unique()->all()))
            // Never touch an event still waiting to be pushed upstream.
            ->where('needs_push', false)
            ->where('start_at', '>=', $from)
            ->where('start_at', '<=', $to)
            ->get()
            ->each->delete()
            ->count();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    protected function window(): array
    {
        return [
            CarbonImmutable::now('UTC')->subDays((int) config('familyhub.caldav.window_days_back'))->startOfDay(),
            CarbonImmutable::now('UTC')->addDays((int) config('familyhub.caldav.window_days_forward'))->endOfDay(),
        ];
    }
}
